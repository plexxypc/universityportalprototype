<?php

declare(strict_types=1);

namespace App\Services;

/**
 * How a programme, level, and session would be invoiced.
 *
 * The lists are student ids. Confirm classifies again under a lock
 * and does not trust this result.
 */
final class InvoicePreview
{
    /**
     * @param  list<int>  $will_create
     * @param  list<int>  $already_invoiced
     * @param  list<int>  $no_fee_structure
     */
    public function __construct(
        public array $will_create,
        public array $already_invoiced,
        public array $no_fee_structure,
    ) {}
}
