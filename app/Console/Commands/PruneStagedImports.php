<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ImportService;
use Illuminate\Console\Command;

/**
 * Delete staged import files that are older than 24 hours.
 */
final class PruneStagedImports extends Command
{
    protected $signature = 'imports:prune-staged';

    protected $description = 'Delete staged import files older than 24 hours.';

    /**
     * Remove staged files whose saved time is before the cutoff.
     */
    public function handle(ImportService $imports): int
    {
        $removed = $imports->pruneStaged(now()->subHours(24));
        $this->info('Removed '.$removed.' staged import file(s).');

        return self::SUCCESS;
    }
}
