<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * HTTPS mail adapter.
 *
 * The adapter reports an HTTP status code and nothing from the response body.
 */
interface MailTransport
{
    /**
     * Status from the last attempt, or null when no attempt has finished.
     */
    public function statusCode(): ?int;
}
