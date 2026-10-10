<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PasswordResetService;

/**
 * Look up the account and queue a reset email after the HTTP response.
 *
 * This is not a queue job. The identifier is not a reset token. The token
 * is created inside PasswordResetService after this runs.
 */
final class IssuePasswordResetLink
{
    /**
     * The same application handles every request in a test, and terminate
     * runs again on the next call. This flag keeps one request from issuing
     * a second token.
     */
    private bool $handled = false;

    /**
     * Remember the normalised email or matric number.
     */
    public function __construct(public string $normalised_identifier) {}

    /**
     * Issue the link, or do the dummy hash work when no active account matches.
     */
    public function handle(PasswordResetService $resets): void
    {
        if ($this->handled) {
            return;
        }

        $this->handled = true;
        $resets->issue($this->normalised_identifier);
    }
}
