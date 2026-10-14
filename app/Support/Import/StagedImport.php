<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * One actor's parsed spreadsheet, stored on the private disk.
 */
final readonly class StagedImport
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @param  array<string, int>|null  $column_map
     */
    public function __construct(
        public string $token,
        public int $user_id,
        public string $source,
        public string $original_name,
        public string $created_at,
        public array $headers,
        public array $rows,
        public ?array $column_map,
        public ?int $import_batch_id,
    ) {}
}
