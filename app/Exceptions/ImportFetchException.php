<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A Google Sheet download was refused before any student row was written.
 *
 * The message is one of the fixed sentences. It does not include the link,
 * the host, or an address.
 */
final class ImportFetchException extends RuntimeException
{
    public const string MESSAGE_LINK = 'Use a Google Sheets link that starts with https://docs.google.com/spreadsheets/d/.';

    public const string MESSAGE_PRIVATE = 'Share the sheet as anyone with the link can view.';

    public const string MESSAGE_DOWNLOAD = 'The sheet could not be downloaded.';

    public const string MESSAGE_SIZE = 'The sheet is larger than 5 MB.';
}
