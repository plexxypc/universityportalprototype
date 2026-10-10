<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\Facades\Http;
use Stringable;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Brevo HTTPS transport.
 *
 * The host comes from configuration. This class adds the provider path and
 * JSON shape. It records the HTTP status only and does not throw, log, or
 * store the response body.
 */
final class BrevoTransport extends AbstractTransport implements MailTransport, Stringable
{
    private ?int $status_code = null;

    public function __construct(
        private readonly string $api_url,
        private readonly string $api_key,
        private readonly int $timeout_seconds,
    ) {
        parent::__construct();
    }

    /**
     * Status from the last attempt, or null before the first attempt.
     */
    public function statusCode(): ?int
    {
        return $this->status_code;
    }

    /**
     * Hide the API key if the object is dumped.
     *
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return [
            'timeout_seconds' => $this->timeoutSeconds(),
        ];
    }

    public function __toString(): string
    {
        return 'brevo';
    }

    /**
     * POST the message. A transport failure sets status 0 and does not throw.
     */
    protected function doSend(SentMessage $message): void
    {
        $this->status_code = 0;

        $endpoint = $this->endpoint();
        $original = $message->getOriginalMessage();
        $email = $original instanceof Email ? $original : null;
        $recipient = $email?->getTo()[0] ?? null;

        if ($endpoint === null || $this->api_key === '' || $email === null || $recipient === null) {
            return;
        }

        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $payload = [
            'sender' => [
                'email' => (string) config('mail.from.address'),
                'name' => (string) config('mail.from.name'),
            ],
            'to' => [
                ['email' => $recipient->getAddress()],
            ],
            'subject' => (string) $email->getSubject(),
            'htmlContent' => is_string($html) ? $html : '',
            'textContent' => is_string($text) ? $text : '',
        ];

        try {
            $response = Http::timeout($this->timeoutSeconds())
                ->connectTimeout($this->timeoutSeconds())
                ->withHeaders([
                    'api-key' => $this->api_key,
                    'accept' => 'application/json',
                ])
                ->asJson()
                ->post($endpoint, $payload);

            $this->status_code = $response->status();
        } catch (Throwable) {
            $this->status_code = 0;
        }
    }

    /**
     * HTTPS host from configuration, plus the provider path.
     */
    private function endpoint(): ?string
    {
        $parts = parse_url($this->api_url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';

        if ($scheme !== 'https' || $host === '') {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port.'/v3/smtp/email';
    }

    /**
     * Short request timeout. A missing or zero value stays at 10 seconds.
     */
    private function timeoutSeconds(): int
    {
        return $this->timeout_seconds > 0 ? $this->timeout_seconds : 10;
    }
}
