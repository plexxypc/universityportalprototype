<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The outcome of issuing credentials, with no password in it.
 */
final readonly class CredentialDelivery
{
    public const string QUEUED = 'queued';

    public const string SENT = 'sent';

    public const string NOT_SENT = 'not_sent';

    public function __construct(public string $state) {}
}
