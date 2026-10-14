<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ImportFetchException;
use App\Exceptions\ImportReadException;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Support\Import\GoogleSheetClient;
use App\Support\Import\SpreadsheetReader;
use App\Support\Import\StagedImport;
use App\Support\Import\StagedImportStore;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Stages a student spreadsheet and reads it back for the actor who owns it.
 *
 * Parsed rows stay in the private staged file. Callers pass a token.
 */
final class ImportService
{
    public const string MESSAGE_RATE = 'Wait a few minutes before importing another file.';

    public const string MESSAGE_FILE = 'Choose a CSV or Excel file.';

    public const string MESSAGE_SHEET = 'Paste a Google Sheets link.';

    public const int SOURCE_ATTEMPTS = 10;

    public const int SOURCE_DECAY_SECONDS = 600;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly StagedImportStore $store,
    ) {}

    /**
     * Parse an upload and stage it for this actor.
     */
    public function stageUpload(User $actor, UploadedFile $file): string
    {
        $this->authorise($actor);
        $this->limitSources($actor);
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['upload' => ImportReadException::MESSAGE_TYPE]);
        }

        $directory = storage_path('app/private/imports/tmp');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages(['upload' => ImportReadException::MESSAGE_READ]);
        }

        $temporary = $directory.'/'.bin2hex(random_bytes(16)).'.'.$extension;

        try {
            $contents = $file->getContent();
            if (file_put_contents($temporary, $contents) === false) {
                throw ValidationException::withMessages(['upload' => ImportReadException::MESSAGE_READ]);
            }

            $sheet = $this->reader->read($temporary);
        } catch (ImportReadException $exception) {
            throw ValidationException::withMessages(['upload' => $exception->getMessage()]);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $this->store->put(
            (int) $actor->id,
            $extension,
            $this->originalName($file->getClientOriginalName(), $extension),
            $sheet,
        );
    }

    /**
     * Download a public Google Sheet and stage its CSV.
     */
    public function stageSheet(User $actor, string $link): string
    {
        $this->authorise($actor);
        $this->limitSources($actor);

        if (trim($link) === '') {
            throw ValidationException::withMessages(['sheet_url' => self::MESSAGE_SHEET]);
        }

        try {
            $csv = $this->google()->fetch($link);
            $sheet = $this->reader->readCsv($csv);
        } catch (ImportFetchException|ImportReadException $exception) {
            throw ValidationException::withMessages(['sheet_url' => $exception->getMessage()]);
        }

        return $this->store->put((int) $actor->id, 'google_sheet', 'Google Sheet', $sheet);
    }

    /**
     * Headers and the first rows. The full sheet stays in the staged file.
     *
     * @return array{headers: list<string>, rows: list<list<string>>, total_rows: int, source: string, original_name: string}
     */
    public function preview(User $actor, string $token): array
    {
        $this->authorise($actor);
        $staged = $this->owned($actor, $token);

        return [
            'headers' => $staged->headers,
            'rows' => array_slice($staged->rows, 0, StagedImportStore::PREVIEW_ROWS),
            'total_rows' => count($staged->rows),
            'source' => $staged->source,
            'original_name' => $staged->original_name,
        ];
    }

    /**
     * Delete staged files older than the cutoff.
     */
    public function pruneStaged(CarbonInterface $cutoff): int
    {
        return $this->store->prune($cutoff);
    }

    /**
     * The actor's staged sheet, or the same not-found exception for every miss.
     */
    public function owned(User $actor, string $token): StagedImport
    {
        $this->authorise($actor);

        return $this->store->read((int) $actor->id, $token);
    }

    /**
     * Refuse a caller who cannot manage student records.
     */
    private function authorise(User $actor): void
    {
        if (! app(StudentPolicy::class)->create($actor)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Ten source uploads or fetches in ten minutes for one actor.
     */
    private function limitSources(User $actor): void
    {
        $key = 'import-source:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::SOURCE_ATTEMPTS)) {
            throw ValidationException::withMessages(['upload' => self::MESSAGE_RATE]);
        }

        RateLimiter::hit($key, self::SOURCE_DECAY_SECONDS);
    }

    /**
     * The bound client in tests, otherwise the real pinned client.
     */
    private function google(): GoogleSheetClient
    {
        if (app()->bound(GoogleSheetClient::class)) {
            return app(GoogleSheetClient::class);
        }

        return GoogleSheetClient::make();
    }

    private function originalName(string $name, string $extension): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $cleaned = preg_replace('/[^\w.\- ]+/u', '', $base);
        $cleaned = is_string($cleaned) ? trim($cleaned) : '';

        if ($cleaned === '') {
            return 'students.'.$extension;
        }

        return mb_substr($cleaned, 0, 180);
    }
}
