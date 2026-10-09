<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment providers from ADR-023 and PAY-10: demo, remita, interswitch.
 *
 * The column is plain varchar. Adding a provider needs no migration.
 */
enum PaymentGateway: string
{
    case Demo = 'demo';
    case Remita = 'remita';
    case Interswitch = 'interswitch';
}
