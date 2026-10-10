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
 * body_html and body_text hold a placeholder for credential and reset mail.
 * The render payload for those templates lives in secrets until send.
 * Other templates store the escaped body and leave secrets null.
 */
#[Fillable([
    'user_id',
    'recipient_email',
    'template',
    'subject',
    'body_html',
    'body_text',
    'secrets',
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
     * The encrypted payload must not appear in arrays or JSON.
     *
     * @var list<string>
     */
    protected $hidden = [
        'secrets',
    ];

    /**
     * Cast the status, the secret payload, and the send and redaction instants.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmailStatus::class,
            'secrets' => 'encrypted:array',
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
