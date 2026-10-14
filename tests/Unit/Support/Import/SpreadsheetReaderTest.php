<?php

declare(strict_types=1);

use App\Exceptions\ImportReadException;
use App\Support\Import\ParsedSheet;
use App\Support\Import\SpreadsheetLimits;
use App\Support\Import\SpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * @return list<string>
 */
function import_temp_files(): array
{
    return $GLOBALS['import_temp_files'] ?? [];
}

function import_track(string $path): string
{
    $GLOBALS['import_temp_files'][] = $path;

    return $path;
}

function import_csv_path(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp');

    if ($path === false) {
        throw new RuntimeException('Temp file failed.');
    }

    $target = $path.'.csv';
    rename($path, $target);
    file_put_contents($target, $contents);

    return import_track($target);
}

/**
 * @param  callable(Spreadsheet): void  $fill
 */
function import_xlsx_path(callable $fill): string
{
    $path = tempnam(sys_get_temp_dir(), 'imp');

    if ($path === false) {
        throw new RuntimeException('Temp file failed.');
    }

    $target = $path.'.xlsx';
    rename($path, $target);
    $spreadsheet = new Spreadsheet;
    $fill($spreadsheet);
    $writer = new Xlsx($spreadsheet);
    $writer->save($target);
    $spreadsheet->disconnectWorksheets();

    return import_track($target);
}

afterEach(function () {
    foreach (import_temp_files() as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }

    $GLOBALS['import_temp_files'] = [];
});

it('reads a csv and strips a utf-8 bom', function () {
    $path = import_csv_path("\xEF\xBB\xBFfirst_name,last_name\r\nAda,Lovelace\r\n");
    $sheet = (new SpreadsheetReader)->read($path);

    expect($sheet)->toBeInstanceOf(ParsedSheet::class)
        ->and($sheet->headers)->toBe(['first_name', 'last_name'])
        ->and($sheet->rows)->toBe([['Ada', 'Lovelace']]);
});

it('keeps a csv formula as text', function () {
    $path = import_csv_path("first_name\n=1+1\n");
    $sheet = (new SpreadsheetReader)->read($path);

    expect($sheet->rows)->toBe([['=1+1']]);
});

it('reads quoted commas and newlines', function () {
    $path = import_csv_path("first_name,address\nAda,\"line1\nline2, west\"\n");
    $sheet = (new SpreadsheetReader)->read($path);

    expect($sheet->rows)->toBe([['Ada', "line1\nline2, west"]]);
});

it('rejects binary junk, a utf-16 mark, and a zip renamed as csv', function () {
    $reader = new SpreadsheetReader;

    expect(fn () => $reader->read(import_csv_path("first_name\0Ada")))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_BINARY);
    expect(fn () => $reader->read(import_csv_path("\xFF\xFEfirst_name")))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_BINARY);
    expect(fn () => $reader->read(import_csv_path("PK\x03\x04not-csv")))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_BINARY);
    expect(fn () => $reader->read(import_csv_path("first_name\n\xC3\x28\n")))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_BINARY);
});

it('rejects a csv larger than 5 MB and one with more than 5000 data rows', function () {
    $reader = new SpreadsheetReader;
    $oversize = import_csv_path(str_repeat('a', SpreadsheetLimits::MAX_BYTES + 1));
    $lines = ['first_name'];

    for ($row = 0; $row < SpreadsheetLimits::MAX_DATA_ROWS + 1; $row++) {
        $lines[] = 'Ada';
    }

    $too_many = import_csv_path(implode("\n", $lines)."\n");

    expect(fn () => $reader->read($oversize))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_SIZE);
    expect(fn () => $reader->read($too_many))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_ROWS);
});

it('accepts exactly 5000 data rows', function () {
    $lines = ['first_name'];

    for ($row = 0; $row < SpreadsheetLimits::MAX_DATA_ROWS; $row++) {
        $lines[] = 'Ada';
    }

    $sheet = (new SpreadsheetReader)->read(import_csv_path(implode("\n", $lines)."\n"));

    expect($sheet->rows)->toHaveCount(SpreadsheetLimits::MAX_DATA_ROWS);
});

it('rejects a renamed non-spreadsheet and a zip that is not a workbook', function () {
    $pdf = tempnam(sys_get_temp_dir(), 'imp');
    expect($pdf)->not->toBeFalse();
    $pdf_path = $pdf.'.xlsx';
    rename($pdf, $pdf_path);
    unlink($pdf_path);
    file_put_contents($pdf_path, "%PDF-1.4\n");
    import_track($pdf_path);

    $zip_path = tempnam(sys_get_temp_dir(), 'imp');
    expect($zip_path)->not->toBeFalse();
    $archive_path = $zip_path.'.xlsx';
    rename($zip_path, $archive_path);
    unlink($archive_path);
    import_track($archive_path);
    $zip = new ZipArchive;
    $zip->open($archive_path, ZipArchive::CREATE);
    $zip->addFromString('hello.txt', 'hello');
    $zip->close();

    $reader = new SpreadsheetReader;

    expect(fn () => $reader->read($pdf_path))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_TYPE);
    expect(fn () => $reader->read($archive_path))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_TYPE);
});

it('reads the first sheet and does not evaluate a formula', function () {
    $path = import_xlsx_path(function (Spreadsheet $spreadsheet): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'first_name');
        $sheet->setCellValue('A2', '=1+1');
        $sheet->getCell('A2')->getCalculatedValue();
        $sheet->setCellValue('B1', 'last_name');
        $sheet->setCellValue('B2', 'Lovelace');
        $other = $spreadsheet->createSheet();
        $other->setCellValue('A1', 'ignored');
        $other->setCellValue('A2', 'Other');
    });

    $sheet = (new SpreadsheetReader)->read($path);

    expect($sheet->headers)->toBe(['first_name', 'last_name'])
        ->and($sheet->rows)->toBe([['=1+1', 'Lovelace']]);
});

it('reads an excel date as a calendar date', function () {
    $path = import_xlsx_path(function (Spreadsheet $spreadsheet): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setCellValue('A1', 'date_of_birth');
        $sheet->setCellValue('A2', Date::PHPToExcel(new DateTimeImmutable('2001-02-03')));
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('YYYY-MM-DD');
    });

    $sheet = (new SpreadsheetReader)->read($path);

    expect($sheet->rows)->toBe([['2001-02-03']]);
});

it('rejects a macro workbook and a vba project entry', function () {
    $macro = import_xlsx_path(function (Spreadsheet $spreadsheet): void {
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'first_name');
    });
    $zip = new ZipArchive;
    $zip->open($macro);
    $types = $zip->getFromName('[Content_Types].xml');
    expect($types)->toBeString();
    $zip->addFromString(
        '[Content_Types].xml',
        str_replace('spreadsheetml.sheet.main+xml', 'spreadsheetml.sheet.macroEnabled.main+xml', (string) $types),
    );
    $zip->close();

    $vba = import_xlsx_path(function (Spreadsheet $spreadsheet): void {
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'first_name');
    });
    $vba_zip = new ZipArchive;
    $vba_zip->open($vba);
    $vba_zip->addFromString('xl/vbaProject.bin', 'not-a-macro');
    $vba_zip->close();

    $reader = new SpreadsheetReader;

    expect(fn () => $reader->read($macro))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_MACRO);
    expect(fn () => $reader->read($vba))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_MACRO);
});

it('rejects a zip with too many entries', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp');
    expect($path)->not->toBeFalse();
    $target = $path.'.xlsx';
    rename($path, $target);
    unlink($target);
    import_track($target);
    $zip = new ZipArchive;
    $zip->open($target, ZipArchive::CREATE);

    for ($index = 0; $index < SpreadsheetLimits::MAX_ZIP_ENTRIES + 1; $index++) {
        $zip->addFromString('entry-'.$index.'.txt', 'x');
    }

    $zip->close();

    expect(fn () => (new SpreadsheetReader)->read($target))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_ZIP);
});

it('rejects a zip whose uncompressed size exceeds the cap', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp');
    expect($path)->not->toBeFalse();
    $target = $path.'.xlsx';
    rename($path, $target);
    unlink($target);
    import_track($target);
    $zip = new ZipArchive;
    $zip->open($target, ZipArchive::CREATE);
    $zip->addFromString('xl/workbook.xml', str_repeat('0', SpreadsheetLimits::MAX_UNCOMPRESSED_BYTES + 1));
    $zip->close();

    expect(fn () => (new SpreadsheetReader)->read($target))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_ZIP);
});

it('rejects an xlsm extension and a row with too many columns', function () {
    $path = tempnam(sys_get_temp_dir(), 'imp');
    expect($path)->not->toBeFalse();
    $target = $path.'.xlsm';
    rename($path, $target);
    unlink($target);
    file_put_contents($target, "PK\x03\x04");
    import_track($target);

    $wide = import_csv_path('a,'.implode(',', array_fill(0, SpreadsheetLimits::MAX_COLUMNS, 'b'))."\n");
    $reader = new SpreadsheetReader;

    expect(fn () => $reader->read($target))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_TYPE);
    expect(fn () => $reader->read($wide))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_COLUMNS);
});

it('rejects an empty csv', function () {
    expect(fn () => (new SpreadsheetReader)->read(import_csv_path("\n\n")))
        ->toThrow(ImportReadException::class, ImportReadException::MESSAGE_EMPTY);
});
