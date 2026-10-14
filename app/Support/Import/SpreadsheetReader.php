<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Exceptions\ImportReadException;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;
use ZipArchive;

/**
 * Reads a CSV or the first sheet of a plain .xlsx file.
 *
 * Formulas are returned as text. Calculated values are never requested.
 */
final class SpreadsheetReader
{
    /**
     * Read a .csv or .xlsx path. The extension must match the sniffed content.
     */
    public function read(string $path): ParsedSheet
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw new ImportReadException(ImportReadException::MESSAGE_TYPE);
        }

        $size = filesize($path);

        if ($size === false) {
            throw new ImportReadException(ImportReadException::MESSAGE_READ);
        }

        if ($size > SpreadsheetLimits::MAX_BYTES) {
            throw new ImportReadException(ImportReadException::MESSAGE_SIZE);
        }

        if ($extension === 'csv') {
            $contents = file_get_contents($path);

            if ($contents === false) {
                throw new ImportReadException(ImportReadException::MESSAGE_READ);
            }

            return $this->readCsv($contents);
        }

        return $this->readXlsx($path);
    }

    /**
     * Parse CSV text that is already in memory, including a downloaded sheet.
     */
    public function readCsv(string $contents): ParsedSheet
    {
        if (strlen($contents) > SpreadsheetLimits::MAX_BYTES) {
            throw new ImportReadException(ImportReadException::MESSAGE_SIZE);
        }

        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }

        if ($this->csvIsBinary($contents)) {
            throw new ImportReadException(ImportReadException::MESSAGE_BINARY);
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw new ImportReadException(ImportReadException::MESSAGE_BINARY);
        }

        $stream = fopen('php://temp', 'rb+');

        if ($stream === false) {
            throw new ImportReadException(ImportReadException::MESSAGE_READ);
        }

        fwrite($stream, $contents);
        rewind($stream);

        /** @var list<list<string>> $parsed */
        $parsed = [];
        $seen = 0;

        try {
            while (true) {
                $row = fgetcsv($stream, null, ',', '"', '');

                if ($row === false) {
                    break;
                }

                $seen++;

                if ($seen > SpreadsheetLimits::MAX_SCAN_ROWS) {
                    throw new ImportReadException(ImportReadException::MESSAGE_ROWS);
                }

                $cells = [];

                foreach ($row as $cell) {
                    $cells[] = trim((string) $cell);
                }

                $parsed[] = $cells;
            }
        } finally {
            fclose($stream);
        }

        return $this->sheetFromRows($parsed);
    }

    /**
     * Read the first worksheet after the zip limits have been checked.
     */
    private function readXlsx(string $path): ParsedSheet
    {
        $this->assertXlsx($path);

        $reader = new Xlsx;
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);
        $reader->setIgnoreRowsWithNoCells(true);

        $spreadsheet = null;

        try {
            $spreadsheet = $reader->load($path);
            $rows = $this->xlsxRows($spreadsheet);
        } catch (ImportReadException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ImportReadException(ImportReadException::MESSAGE_TYPE);
        } finally {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
        }

        return $this->sheetFromRows($rows);
    }

    /**
     * Refuse a file that is not a small, plain .xlsx workbook.
     */
    private function assertXlsx(string $path): void
    {
        $handle = fopen($path, 'rb');
        $magic = $handle === false ? '' : (string) fread($handle, 4);

        if (is_resource($handle)) {
            fclose($handle);
        }

        if (! str_starts_with($magic, "PK\x03\x04")) {
            throw new ImportReadException(ImportReadException::MESSAGE_TYPE);
        }

        $zip = new ZipArchive;
        $opened = $zip->open($path);

        if ($opened !== true) {
            throw new ImportReadException(ImportReadException::MESSAGE_TYPE);
        }

        try {
            if ($zip->numFiles > SpreadsheetLimits::MAX_ZIP_ENTRIES) {
                throw new ImportReadException(ImportReadException::MESSAGE_ZIP);
            }

            $uncompressed = 0;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    throw new ImportReadException(ImportReadException::MESSAGE_TYPE);
                }

                $uncompressed += (int) $stat['size'];

                if ($uncompressed > SpreadsheetLimits::MAX_UNCOMPRESSED_BYTES) {
                    throw new ImportReadException(ImportReadException::MESSAGE_ZIP);
                }

                $name = strtolower((string) $stat['name']);

                if (str_contains($name, 'vbaproject')) {
                    throw new ImportReadException(ImportReadException::MESSAGE_MACRO);
                }
            }

            $content_stat = $zip->statName('[Content_Types].xml');

            if ($content_stat === false) {
                throw new ImportReadException(ImportReadException::MESSAGE_TYPE);
            }

            if ((int) $content_stat['size'] > SpreadsheetLimits::MAX_CONTENT_TYPES_BYTES) {
                throw new ImportReadException(ImportReadException::MESSAGE_ZIP);
            }

            $content_types = $zip->getFromName('[Content_Types].xml');

            if (! is_string($content_types)) {
                throw new ImportReadException(ImportReadException::MESSAGE_TYPE);
            }

            $lower = strtolower($content_types);

            if (str_contains($lower, 'macroenabled') || str_contains($lower, 'vbaproject')) {
                throw new ImportReadException(ImportReadException::MESSAGE_MACRO);
            }

            if (! str_contains($lower, 'spreadsheetml.sheet.main+xml')) {
                throw new ImportReadException(ImportReadException::MESSAGE_TYPE);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<list<string>>
     */
    private function xlsxRows(Spreadsheet $spreadsheet): array
    {
        $sheet = $spreadsheet->getSheet(0);
        $highest_row = $sheet->getHighestDataRow();

        if ($highest_row > SpreadsheetLimits::MAX_SCAN_ROWS) {
            throw new ImportReadException(ImportReadException::MESSAGE_ROWS);
        }

        $highest_column = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        if ($highest_column > SpreadsheetLimits::MAX_COLUMNS) {
            throw new ImportReadException(ImportReadException::MESSAGE_COLUMNS);
        }

        /** @var list<list<string>> $rows */
        $rows = [];

        for ($row_index = 1; $row_index <= $highest_row; $row_index++) {
            $cells = [];

            for ($column = 1; $column <= $highest_column; $column++) {
                $cells[] = $this->cellText($sheet->getCell([$column, $row_index]));
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * Formula cells stay as the formula string. Other cells become text.
     */
    private function cellText(Cell $cell): string
    {
        if ($cell->isFormula()) {
            return trim((string) $cell->getValue());
        }

        $value = $cell->getValue();

        if ($value instanceof RichText) {
            return trim($value->getPlainText());
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (is_int($value) || is_float($value)) {
            if (Date::isDateTime($cell)) {
                return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            if (is_int($value) || (floor($value) === $value && abs($value) < 1_000_000_000_000_000)) {
                return sprintf('%.0F', $value);
            }

            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function sheetFromRows(array $rows): ParsedSheet
    {
        /** @var list<list<string>> $filled */
        $filled = [];

        foreach ($rows as $row) {
            $trimmed = $this->trimRow($row);

            if ($trimmed === []) {
                continue;
            }

            $filled[] = $trimmed;

            if (count($filled) > SpreadsheetLimits::MAX_DATA_ROWS + 1) {
                throw new ImportReadException(ImportReadException::MESSAGE_ROWS);
            }
        }

        if ($filled === []) {
            throw new ImportReadException(ImportReadException::MESSAGE_EMPTY);
        }

        $headers = $filled[0];
        $data = array_slice($filled, 1);

        return new ParsedSheet($headers, $data);
    }

    /**
     * @param  list<string>  $row
     * @return list<string>
     */
    private function trimRow(array $row): array
    {
        while ($row !== [] && $row[array_key_last($row)] === '') {
            array_pop($row);
        }

        if (count($row) > SpreadsheetLimits::MAX_COLUMNS) {
            throw new ImportReadException(ImportReadException::MESSAGE_COLUMNS);
        }

        return $row;
    }

    /**
     * NUL bytes, UTF-16 marks, and zip bytes are not CSV text.
     */
    private function csvIsBinary(string $contents): bool
    {
        if ($contents === '') {
            return false;
        }

        if (str_contains($contents, "\0")) {
            return true;
        }

        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            return true;
        }

        return str_starts_with($contents, "PK\x03\x04");
    }
}
