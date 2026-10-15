<?php

declare(strict_types=1);

namespace App\Support\Import;

use App\Exceptions\ImportNotFoundException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

/**
 * Private staged spreadsheets keyed by a random token.
 *
 * Files live on the local disk, which is outside the public web root.
 * A token that is missing, forged, or owned by someone else is the same miss.
 */
final class StagedImportStore
{
    public const string DIRECTORY = 'imports/staged';

    public const int PREVIEW_ROWS = 20;

    /**
     * Store a sheet for this actor and remove that actor's older staged files.
     *
     * @return string The new token.
     */
    public function put(int $user_id, string $source, string $original_name, ParsedSheet $sheet, string $target = 'Student'): string
    {
        $token = bin2hex(random_bytes(32));
        $this->deleteOwnedBy($user_id);

        $payload = [
            'user_id' => $user_id,
            'source' => $source,
            'target' => $target,
            'original_name' => $original_name,
            'created_at' => now()->toIso8601String(),
            'headers' => $sheet->headers,
            'rows' => $sheet->rows,
            'column_map' => null,
            'import_batch_id' => null,
        ];

        Storage::disk('local')->put($this->path($token), $this->encode($payload));

        return $token;
    }

    /**
     * The staged sheet when this actor owns the token.
     */
    public function read(int $user_id, string $token): StagedImport
    {
        $payload = $this->payload($token);
        $owner = $payload['user_id'] ?? null;

        if (! is_int($owner) && ! (is_string($owner) && ctype_digit($owner))) {
            throw ImportNotFoundException::missing();
        }

        if ((int) $owner !== $user_id) {
            throw ImportNotFoundException::missing();
        }

        return $this->staged($token, $payload);
    }

    /**
     * Replace the saved column map. Rows stay as they were parsed.
     *
     * @param  array<string, int>  $column_map
     */
    public function saveMap(int $user_id, string $token, array $column_map): StagedImport
    {
        $payload = $this->ownedPayload($user_id, $token);
        $payload['column_map'] = $column_map;
        Storage::disk('local')->put($this->path($token), $this->encode($payload));

        return $this->staged($token, $payload);
    }

    /**
     * Remember the import batch id on the staged file.
     */
    public function saveBatchId(int $user_id, string $token, int $batch_id): void
    {
        $payload = $this->ownedPayload($user_id, $token);
        $payload['import_batch_id'] = $batch_id;
        Storage::disk('local')->put($this->path($token), $this->encode($payload));
    }

    /**
     * Remove one staged file after the report has been written.
     */
    public function delete(int $user_id, string $token): void
    {
        $this->ownedPayload($user_id, $token);
        Storage::disk('local')->delete($this->path($token));
    }

    /**
     * Remove every staged file this actor still has.
     */
    public function deleteOwnedBy(int $user_id): void
    {
        foreach ($this->tokens() as $token) {
            try {
                $payload = $this->payload($token);
            } catch (ImportNotFoundException) {
                Storage::disk('local')->delete($this->path($token));

                continue;
            }

            $owner = $payload['user_id'] ?? null;

            if ((is_int($owner) || (is_string($owner) && ctype_digit($owner))) && (int) $owner === $user_id) {
                Storage::disk('local')->delete($this->path($token));
            }
        }
    }

    /**
     * Remove staged files whose saved time is older than the cutoff.
     */
    public function prune(CarbonInterface $cutoff): int
    {
        $removed = 0;

        foreach ($this->tokens() as $token) {
            if (! $this->olderThan($token, $cutoff)) {
                continue;
            }

            Storage::disk('local')->delete($this->path($token));
            $removed++;
        }

        return $removed;
    }

    /**
     * Whether this token can be used as a file name.
     */
    public function validToken(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedPayload(int $user_id, string $token): array
    {
        $payload = $this->payload($token);
        $owner = $payload['user_id'] ?? null;

        if ((! is_int($owner) && ! (is_string($owner) && ctype_digit($owner))) || (int) $owner !== $user_id) {
            throw ImportNotFoundException::missing();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $token): array
    {
        if (! $this->validToken($token)) {
            throw ImportNotFoundException::missing();
        }

        $path = $this->path($token);

        if (! Storage::disk('local')->exists($path)) {
            throw ImportNotFoundException::missing();
        }

        try {
            $decoded = json_decode(Storage::disk('local')->get($path) ?: '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ImportNotFoundException::missing();
        }

        if (! is_array($decoded)) {
            throw ImportNotFoundException::missing();
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function staged(string $token, array $payload): StagedImport
    {
        $headers = $this->stringList($payload['headers'] ?? null);
        $rows = $this->rowList($payload['rows'] ?? null);
        $map = $payload['column_map'] ?? null;
        $batch = $payload['import_batch_id'] ?? null;
        $target = $payload['target'] ?? 'Student';

        return new StagedImport(
            $token,
            (int) $payload['user_id'],
            is_string($payload['source'] ?? null) ? $payload['source'] : '',
            is_string($payload['original_name'] ?? null) ? $payload['original_name'] : '',
            is_string($payload['created_at'] ?? null) ? $payload['created_at'] : '',
            $headers,
            $rows,
            is_array($map) ? $this->map($map) : null,
            is_int($batch) ? $batch : (is_string($batch) && ctype_digit($batch) ? (int) $batch : null),
            is_string($target) && $target !== '' ? $target : 'Student',
        );
    }

    /**
     * @return list<string>
     */
    private function tokens(): array
    {
        $tokens = [];

        foreach (Storage::disk('local')->files(self::DIRECTORY) as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);

            if ($this->validToken($name)) {
                $tokens[] = $name;
            }
        }

        return $tokens;
    }

    private function olderThan(string $token, CarbonInterface $cutoff): bool
    {
        try {
            $payload = $this->payload($token);
            $created = $payload['created_at'] ?? null;

            if (is_string($created) && $created !== '') {
                return Carbon::parse($created)->lt($cutoff);
            }
        } catch (Throwable) {
            return true;
        }

        return Storage::disk('local')->lastModified($this->path($token)) < $cutoff->getTimestamp();
    }

    private function path(string $token): string
    {
        return self::DIRECTORY.'/'.$token.'.json';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            $list[] = is_string($item) ? $item : '';
        }

        return $list;
    }

    /**
     * @return list<list<string>>
     */
    private function rowList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            $rows[] = $this->stringList($row);
        }

        return $rows;
    }

    /**
     * @param  array<mixed, mixed>  $map
     * @return array<string, int>
     */
    private function map(array $map): array
    {
        $parsed = [];

        foreach ($map as $field => $index) {
            if (! is_string($field)) {
                continue;
            }

            if (is_int($index)) {
                $parsed[$field] = $index;
            } elseif (is_string($index) && ctype_digit($index)) {
                $parsed[$field] = (int) $index;
            }
        }

        return $parsed;
    }
}
