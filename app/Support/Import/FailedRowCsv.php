<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Support\CsvSafe;
use Illuminate\Support\Facades\Storage;

/**
 * Failed import rows on the private disk.
 *
 * Every cell goes through CsvSafe so a leading equals sign stays text.
 * The file is not served from a public path.
 */
final class FailedRowCsv
{
    public const string DIRECTORY = 'imports/failed';

    /**
     * Append one failed row. The header is written the first time.
     *
     * @param  array<string, string>  $cells
     */
    public function append(string $token, int $row, string $field, string $message, array $cells): void
    {
        $path = $this->path($token);
        $absolute = Storage::disk('local')->path($path);
        $directory = dirname($absolute);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return;
        }

        $fresh = ! is_file($absolute);
        $handle = fopen($absolute, 'ab');

        if ($handle === false) {
            return;
        }

        if ($fresh) {
            fputcsv($handle, ['row', 'field', 'message', ...array_keys($cells)], ',', '"', '');
        }

        $line = [
            CsvSafe::cell((string) $row),
            CsvSafe::cell($field),
            CsvSafe::cell($message),
        ];

        foreach ($cells as $value) {
            $line[] = CsvSafe::cell($value);
        }

        fputcsv($handle, $line, ',', '"', '');
        fclose($handle);
    }

    public function exists(string $token): bool
    {
        return Storage::disk('local')->exists($this->path($token));
    }

    public function path(string $token): string
    {
        return self::DIRECTORY.'/'.$token.'.csv';
    }
}
