<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One group of at most 25 students from a bulk invoice run.
 *
 * last_student_id is the cursor for the next group. done is true when
 * this group was short of 25, so the caller stops.
 */
final class InvoiceChunk
{
    public function __construct(
        public int $created,
        public int $already_invoiced,
        public int $no_fee_structure,
        public int $last_student_id,
        public bool $done,
    ) {}
}
