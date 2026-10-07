<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Email outbox labels from PRD MAIL-3 and DESIGN.md: Queued, Sent, Failed.
 */
enum EmailStatus: string
{
    case Queued = 'Queued';
    case Sent = 'Sent';
    case Failed = 'Failed';
}
