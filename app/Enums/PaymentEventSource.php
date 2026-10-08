<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a payment event came from: callback, notification, poll, manual.
 *
 * The row records that trigger. A service inserts it and does not update it.
 */
enum PaymentEventSource: string
{
    case Callback = 'callback';
    case Notification = 'notification';
    case Poll = 'poll';
    case Manual = 'manual';
}
