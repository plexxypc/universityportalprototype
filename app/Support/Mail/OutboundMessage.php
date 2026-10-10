<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * Minimal templates until the branded set arrives.
 *
 * Credential and reset payloads are not written into the stored body.
 * Other templates store the escaped render, and their secrets column stays null.
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

    /**
     * Whether this template's render payload is a secret until send.
     */
    public function isSecretTemplate(string $template): bool
    {
        return $template === self::TEMPLATE_CREDENTIALS
            || $template === self::TEMPLATE_PASSWORD_RESET;
    }

    /**
     * Whether this phase can queue the template.
     */
    public function isKnown(string $template): bool
    {
        return $this->isSecretTemplate($template) || $template === self::TEMPLATE_TEST;
    }

    /**
     * Subject stored on the row. Line breaks and secret values are removed.
     *
     * @param  array<string, mixed>  $data
     */
    public function subject(string $template, array $data): string
    {
        $subject = match ($template) {
            self::TEMPLATE_CREDENTIALS => 'Your university portal account',
            self::TEMPLATE_PASSWORD_RESET => 'Reset your university portal password',
            default => (string) ($data['subject'] ?? self::FALLBACK_SUBJECT),
        };

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
        if ($template === self::TEMPLATE_CREDENTIALS) {
            return [
                'name' => $this->stringValue($data, 'name'),
                'matric_no' => $this->stringValue($data, 'matric_no'),
                'login_url' => $this->stringValue($data, 'login_url'),
                'temporary_password' => $this->stringValue($data, 'temporary_password'),
            ];
        }

        return [
            'name' => $this->stringValue($data, 'name'),
            'reset_url' => $this->stringValue($data, 'reset_url'),
            'token' => $this->stringValue($data, 'token'),
        ];
    }

    /**
     * Escaped body for a non-secret template, or null when it cannot be stored.
     *
     * @param  array<string, mixed>  $data
     */
    public function storedBody(array $data): ?RenderedMail
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
        if ($template === self::TEMPLATE_CREDENTIALS) {
            $name = $this->stringValue($payload, 'name');
            $matric = $this->stringValue($payload, 'matric_no');
            $login_url = $this->stringValue($payload, 'login_url');
            $password = $this->stringValue($payload, 'temporary_password');

            if (! $this->linkIsAllowed($login_url) || $password === '' || $matric === '') {
                return null;
            }

            return $this->finish($name."\n".$matric."\n".$login_url."\n".$password, $name."\n".$matric."\n".$login_url."\n".$password);
        }

        $name = $this->stringValue($payload, 'name');
        $reset_url = $this->stringValue($payload, 'reset_url');
        $token = $this->stringValue($payload, 'token');

        if (! $this->linkIsAllowed($reset_url) || $token === '') {
            return null;
        }

        return $this->finish($name."\n".$reset_url."\n".$token, $name."\n".$reset_url."\n".$token);
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

    private function linkIsAllowed(string $url): bool
    {
        $base = rtrim((string) config('app.url'), '/');

        if ($base === '' || preg_match('/[\s\x00]/', $url) === 1) {
            return false;
        }

        return str_starts_with($url, $base.'/');
    }

    private function finish(string $html_source, string $text): ?RenderedMail
    {
        $html = '<p>'.str_replace("\n", '</p><p>', $this->escape($html_source)).'</p>';

        if ($this->hasUnresolvedPlaceholder($html) || $this->hasUnresolvedPlaceholder($text)) {
            return null;
        }

        return new RenderedMail($html, $text);
    }

    private function hasUnresolvedPlaceholder(string $value): bool
    {
        return str_contains($value, '{{') || str_contains($value, ':placeholder');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
