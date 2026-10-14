<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ImportFetchException;
use App\Support\Import\GoogleSheetClient;
use App\Support\Import\SheetFetchLog;
use Illuminate\Console\Command;

/**
 * Download one public Google Sheet and print a safe failure category.
 *
 * The link is typed at the prompt and is not stored in a file.
 */
final class ProbeSheetFetch extends Command
{
    protected $signature = 'import:probe-sheet';

    protected $description = 'Print a safe category for one Google Sheet download.';

    /**
     * Fetch the pasted link and print the category line, not the link.
     */
    public function handle(): int
    {
        $link = $this->secret('Paste the Google Sheets link');

        if (! is_string($link) || trim($link) === '') {
            $this->line('sheet_fetch_failed category=bad_url hop=0');

            return self::FAILURE;
        }

        try {
            $body = GoogleSheetClient::make()->fetch($link);
        } catch (ImportFetchException) {
            $line = SheetFetchLog::last();
            $this->line($line !== '' ? $line : 'sheet_fetch_failed category=unknown hop=0');

            return self::FAILURE;
        }

        $this->line('sheet_fetch_ok bytes='.strlen($body));

        return self::SUCCESS;
    }
}
