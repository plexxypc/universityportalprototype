<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\EmailTemplate;
use InvalidArgumentException;

/**
 * Subjects, secret payloads, and renders for outbox rows.
 *
 * Credential and reset payloads are not written into the stored body.
 * Other branded templates store the escaped render. The test template
 * keeps the short escaped message used by the send path.
 */
final class OutboundMessage
{
    public const string TEMPLATE_CREDENTIALS = 'credentials';

    public const string TEMPLATE_PASSWORD_RESET = 'password_reset';

    public const string TEMPLATE_TEST = 'test';

    public const string HELD_BODY = 'Message held until send.';

    public const string REDACTED_BODY = 'Message removed after send.';

    public const string FALLBACK_SUBJECT = 'Portal message';

    /**
     * @var list<string>
     */
    private const array SECRET_VALUE_KEYS = [
        'password',
        'temporary_password',
        'temp_password',
        'token',
        'reset_token',
    ];

    public function __construct(private readonly EmailTemplateRenderer $renderer) {}

    /**
     * Whether this template's render payload is a secret until send.
     */
    public function isSecretTemplate(string $template): bool
    {
        return EmailTemplate::tryFrom($template)?->isSecret() === true;
    }

    /**
     * Whether this phase can queue the template.
     */
    public function isKnown(string $template): bool
    {
        return EmailTemplate::tryFrom($template) !== null;
    }

    /**
     * Refuse a branded template whose required keys are missing or blank.
     *
     * The test template keeps its existing message path. This runs before
     * a row is inserted. The exception text is a fixed code.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertSendable(string $template, array $data): void
    {
        $case = EmailTemplate::tryFrom($template);

        if ($case === null || $case === EmailTemplate::Test) {
            return;
        }

        $this->renderer->assertRequired($case, $data);
    }

    /**
     * Subject stored on the row. Line breaks and secret values are removed
     * from the test template. Announcement titles that collapse are refused.
     *
     * @param  array<string, mixed>  $data
     */
    public function subject(string $template, array $data): string
    {
        $case = EmailTemplate::tryFrom($template);

        if ($case === EmailTemplate::Announcement) {
            return $this->renderer->announcementSubject($data);
        }

        if ($case !== null && $case !== EmailTemplate::Test) {
            return $this->renderer->fixedSubject($case);
        }

        $subject = (string) ($data['subject'] ?? self::FALLBACK_SUBJECT);

        return $this->sanitizeSubject($subject, $this->secretValues($data));
    }

    /**
     * Render payload stored encrypted for a secret template.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function secretPayload(string $template, array $data): array
    {
        $case = EmailTemplate::tryFrom($template);

        if ($case === null || ! $case->isSecret()) {
            return [];
        }

        return $this->renderer->secretPayload($case, $data);
    }

    /**
     * Branded HTML and text stored on a non-secret row.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderPublic(string $template, array $data): RenderedMail
    {
        $case = EmailTemplate::tryFrom($template);

        if ($case === null || $case === EmailTemplate::Test) {
            throw new InvalidArgumentException(MailError::UNKNOWN_TEMPLATE);
        }

        return $this->renderer->render($case, $data);
    }

    /**
     * Escaped body for the test template.
     *
     * User text is not scanned for placeholder markers.
     *
     * @param  array<string, mixed>  $data
     */
    public function storedBody(array $data): RenderedMail
    {
        $message = $this->stringValue($data, 'message');

        foreach ($this->secretValues($data) as $secret) {
            $message = str_replace($secret, '', $message);
        }

        return $this->finish($message, $message);
    }

    /**
     * In-memory message for a secret template, or null when it must not be sent.
     *
     * @param  array<string, mixed>  $payload
     */
    public function renderSecret(string $template, array $payload): ?RenderedMail
    {
        $case = EmailTemplate::tryFrom($template);

        if ($case === null || ! $case->isSecret()) {
            return null;
        }

        try {
            return $this->renderer->render($case, $payload);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Remove line breaks, then any secret value, from a subject.
     *
     * @param  list<string>  $secret_values
     */
    public function sanitizeSubject(string $subject, array $secret_values): string
    {
        $subject = str_replace(["\r", "\n", "\u{2028}", "\u{2029}"], '', $subject);

        foreach ($secret_values as $secret) {
            if ($secret === '') {
                continue;
            }

            $subject = str_replace($secret, '', $subject);
            $flat = str_replace(["\r", "\n", "\u{2028}", "\u{2029}"], '', $secret);

            if ($flat !== '') {
                $subject = str_replace($flat, '', $subject);
            }
        }

        $subject = trim($subject);

        return $subject === '' ? self::FALLBACK_SUBJECT : $subject;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function secretValues(array $data): array
    {
        $values = [];

        foreach (self::SECRET_VALUE_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    private function finish(string $html_source, string $text): RenderedMail
    {
        $html = '<p>'.str_replace("\n", '</p><p>', $this->escape($html_source)).'</p>';

        return new RenderedMail($html, $text);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
