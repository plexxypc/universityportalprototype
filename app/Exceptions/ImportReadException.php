<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A spreadsheet was refused before any student row was written.
 *
 * The message is one of the fixed sentences. It does not include file
 * contents, cell values, or paths.
 */
final class ImportReadException extends RuntimeException
{
    public const string MESSAGE_SIZE = 'The file is larger than 5 MB.';

    public const string MESSAGE_ROWS = 'The file has more than 5,000 rows.';

    public const string MESSAGE_COLUMNS = 'A row has more than 64 columns.';

    public const string MESSAGE_TYPE = 'This file is not a CSV or Excel spreadsheet.';

    public const string MESSAGE_BINARY = 'This file is not a readable UTF-8 spreadsheet.';

    public const string MESSAGE_ZIP = 'This Excel file is too large to open.';

    public const string MESSAGE_MACRO = 'Upload a plain .xlsx file without macros.';

    public const string MESSAGE_EMPTY = 'The file has no rows.';

    public const string MESSAGE_READ = 'The file could not be read.';
}
