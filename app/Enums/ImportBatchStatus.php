<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Import batch labels: Processing, Completed, Failed.
 */
enum ImportBatchStatus: string
{
    case Processing = 'Processing';
    case Completed = 'Completed';
    case Failed = 'Failed';
}
