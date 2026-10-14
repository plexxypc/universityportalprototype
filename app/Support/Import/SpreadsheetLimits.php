<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * Hard limits for student spreadsheet uploads and Google Sheet downloads.
 */
final class SpreadsheetLimits
{
    public const int MAX_BYTES = 5_242_880;

    public const int MAX_DATA_ROWS = 5_000;

    public const int MAX_COLUMNS = 64;

    /**
     * Rows the reader will scan, including blanks, before refusing the file.
     */
    public const int MAX_SCAN_ROWS = 10_000;

    public const int MAX_ZIP_ENTRIES = 100;

    public const int MAX_UNCOMPRESSED_BYTES = 20_971_520;

    public const int MAX_CONTENT_TYPES_BYTES = 1_048_576;

    public const int CONNECT_TIMEOUT_SECONDS = 5;

    public const int TOTAL_TIMEOUT_SECONDS = 15;

    public const int MAX_REDIRECTS = 3;
}
