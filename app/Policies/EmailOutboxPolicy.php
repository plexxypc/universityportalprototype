<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EmailStatus;
use App\Models\EmailOutbox;
use App\Models\User;
use App\Support\Mail\MailError;
use App\Support\Mail\OutboundMessage;

/**
 * Outbox actions for an Active Super Admin.
 *
 * email_outbox.manage is not in the permission map. Gate::before grants it
 * only to an Active Super Admin. These methods also refuse retry of a
 * credentials or password-reset row, which Gate::before would otherwise allow.
 */
final class EmailOutboxPolicy
{
    /**
     * Whether this account may open the outbox.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('email_outbox.manage');
    }

    /**
     * Whether this account may open one row.
     */
    public function view(User $user, EmailOutbox $row): bool
    {
        return $this->viewAny($user) && $row->getKey() !== null;
    }

    /**
     * Retry a failed row. Credentials and password reset stay refused.
     */
    public function retry(User $user, EmailOutbox $row): bool
    {
        return $this->viewAny($user)
            && $row->status === EmailStatus::Failed
            && ! app(OutboundMessage::class)->isSecretTemplate($row->template);
    }

    /**
     * Send a queued row now, including credentials and password reset.
     */
    public function send(User $user, EmailOutbox $row): bool
    {
        return $this->viewAny($user)
            && $row->status === EmailStatus::Queued
            && $row->attempts < MailError::MAX_ATTEMPTS;
    }

    /**
     * Queue a test email to this account's own address.
     */
    public function sendTest(User $user): bool
    {
        return $this->viewAny($user);
    }
}
