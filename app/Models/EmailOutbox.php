<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmailStatus;
use Database\Factories\EmailOutboxFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rendered email waiting to be sent, or already attempted.
 *
 * body_html and body_text hold the message. Redaction of a credentials email
 * after send, after attempts are exhausted, or when credentials are re-issued,
 * is enforced in a service later (ADR-008).
 */
#[Fillable([
    'user_id',
    'recipient_email',
    'template',
    'subject',
    'body_html',
    'body_text',
    'status',
    'attempts',
    'last_error',
    'sent_at',
    'redacted_at',
])]
class EmailOutbox extends Model
{
    /** @use HasFactory<EmailOutboxFactory> */
    use HasFactory;

    protected $table = 'email_outbox';

    /**
     * Cast the status and the send and redaction instants.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'sent_at' => 'datetime',
            'redacted_at' => 'datetime',
        ];
    }

    /**
     * Account this email was addressed to, when one exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
