<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Support\CsvSafe;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Student import templates for the PRD 6.2 columns.
 *
 * Every cell goes through CsvSafe. The files are built when they are
 * downloaded. There is no password column.
 */
final class ImportTemplate
{
    /**
     * Header keys in import order.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $headers = [];

        foreach (ColumnMap::fields() as $field) {
            $headers[] = $field['key'];
        }

        return $headers;
    }

    /**
     * CSV text. Extra rows are appended after the header.
     *
     * @param  list<list<string>>  $extra_rows
     */
    public function csv(array $extra_rows = []): string
    {
        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            return '';
        }

        foreach ([$this->headers(), ...$extra_rows] as $row) {
            $line = [];

            foreach ($row as $value) {
                $line[] = CsvSafe::cell($value);
            }

            fputcsv($handle, $line, ',', '"', '');
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return is_string($contents) ? $contents : '';
    }

    /**
     * Excel workbook. Extra rows are appended after the header.
     *
     * @param  list<list<string>>  $extra_rows
     */
    public function xlsx(array $extra_rows = []): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ([$this->headers(), ...$extra_rows] as $row_index => $row) {
            foreach ($row as $column_index => $value) {
                $sheet->setCellValue([$column_index + 1, $row_index + 1], CsvSafe::cell($value));
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'import-template');

        if ($path === false) {
            return '';
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $contents = file_get_contents($path);
        unlink($path);

        return is_string($contents) ? $contents : '';
    }
}
