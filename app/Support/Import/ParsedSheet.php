<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * Header row and data rows from one spreadsheet.
 *
 * Cell text is the stored value. A formula is the formula text.
 */
final readonly class ParsedSheet
{
    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function __construct(
        public array $headers,
        public array $rows,
    ) {}
}
