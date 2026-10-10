<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\EmailTemplate;
use App\Support\Dates;
use App\Support\Money;
use InvalidArgumentException;

/**
 * Renders one branded email as HTML and plain text.
 *
 * Links are built from the configured application URL. A missing or blank
 * required key throws before a row is stored. Rendered text is not scanned
 * for placeholder markers, because a message may contain them.
 */
final class EmailTemplateRenderer
{
    private const int SUBJECT_LIMIT = 150;

    private const string EMPTY_DATE = "\u{2014}";

    /**
     * Refuse a branded template whose required data is missing or blank.
     *
     * Blank means null or an empty string after trimming. Zero stays valid
     * for unit totals and amounts. The exception text is a fixed code.
     *
     * @param  array<string, mixed>  $data
     */
    public function assertRequired(EmailTemplate $template, array $data): void
    {
        if ($template === EmailTemplate::Test) {
            return;
        }

        foreach ($template->requiredKeys() as $key) {
            if (! $this->valuePresent($template, $key, $data)) {
                throw new InvalidArgumentException(MailError::MISSING_TEMPLATE_DATA);
            }
        }

        foreach ($template->dateKeys() as $key) {
            $this->formatDate($data[$key]);
        }

        if ($template === EmailTemplate::Announcement) {
            $this->announcementSubject($data);
        }

        $this->baseUrl();
        $this->institutionName();
    }

    /**
     * Subject line for a branded template. Announcements use the title.
     *
     * @param  array<string, mixed>  $data
     */
    public function subject(EmailTemplate $template, array $data): string
    {
        if ($template === EmailTemplate::Announcement) {
            return $this->announcementSubject($data);
        }

        return $this->fixedSubject($template);
    }

    /**
     * Fixed subject for every branded template except the announcement.
     */
    public function fixedSubject(EmailTemplate $template): string
    {
        return match ($template) {
            EmailTemplate::Credentials => 'Your university portal account',
            EmailTemplate::PasswordReset => 'Reset your university portal password',
            EmailTemplate::Admission => 'Your admission is confirmed',
            EmailTemplate::RegistrationConfirmed => 'Your course registration is confirmed',
            EmailTemplate::PaymentConfirmed => 'Your payment is confirmed',
            EmailTemplate::ResultPublished => 'Your result is published',
            EmailTemplate::TimetablePublished => 'The exam timetable is published',
            default => throw new InvalidArgumentException(MailError::UNKNOWN_TEMPLATE),
        };
    }

    /**
     * Announcement subject with line breaks removed and a 150-character cap.
     *
     * A title that is only line breaks is refused. The message is not used.
     *
     * @param  array<string, mixed>  $data
     */
    public function announcementSubject(array $data): string
    {
        if (! array_key_exists('title', $data) || ! is_string($data['title']) || trim($data['title']) === '') {
            throw new InvalidArgumentException(MailError::MISSING_TEMPLATE_DATA);
        }

        $subject = str_replace(["\r", "\n", "\u{2028}", "\u{2029}"], '', $data['title']);
        $subject = trim($subject);

        if ($subject === '') {
            throw new InvalidArgumentException(MailError::INVALID_SUBJECT);
        }

        if (mb_strlen($subject, 'UTF-8') > self::SUBJECT_LIMIT) {
            return mb_substr($subject, 0, self::SUBJECT_LIMIT, 'UTF-8');
        }

        return $subject;
    }

    /**
     * HTML and plain text for one branded template.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(EmailTemplate $template, array $data): RenderedMail
    {
        if ($template === EmailTemplate::Test) {
            throw new InvalidArgumentException(MailError::UNKNOWN_TEMPLATE);
        }

        $this->assertRequired($template, $data);
        $view = $this->viewData($template, $data);

        return new RenderedMail(
            view('emails.content', $view)->render(),
            $this->plainText($view),
        );
    }

    /**
     * Encrypted payload for a secret template. Caller URLs are not stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    public function secretPayload(EmailTemplate $template, array $data): array
    {
        if ($template === EmailTemplate::Credentials) {
            return [
                'name' => $this->text($data, 'name'),
                'login_id' => $this->text($data, 'login_id'),
                'temporary_password' => $this->secretText($data, 'temporary_password'),
                'expires_at' => $this->text($data, 'expires_at'),
            ];
        }

        if ($template === EmailTemplate::PasswordReset) {
            return [
                'name' => $this->text($data, 'name'),
                'token' => $this->secretText($data, 'token'),
            ];
        }

        return [];
    }

    /**
     * Local preview copies. The credentials sample includes a fictional password.
     *
     * @return list<array{name: string, subject: string, html: string, text: string}>
     */
    public function previews(): array
    {
        $items = [];

        foreach (EmailTemplate::cases() as $template) {
            if ($template === EmailTemplate::Test) {
                continue;
            }

            $data = $this->sampleData($template);
            $rendered = $this->render($template, $data);
            $items[] = [
                'name' => $template->value,
                'subject' => $this->subject($template, $data),
                'html' => $rendered->html,
                'text' => $rendered->text,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function valuePresent(EmailTemplate $template, string $key, array $data): bool
    {
        if (! array_key_exists($key, $data)) {
            return false;
        }

        $value = $data[$key];

        if (in_array($key, $template->integerKeys(), true)) {
            return is_int($value);
        }

        if (! is_string($value)) {
            return false;
        }

        return trim($value) !== '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     institution_name: string,
     *     subject: string,
     *     greeting: string,
     *     lines: list<array{label: string, value: string}>,
     *     message_text: string,
     *     message_html: string,
     *     instruction: string,
     *     action_label: string,
     *     action_url: string,
     *     footer: string
     * }
     */
    private function viewData(EmailTemplate $template, array $data): array
    {
        $institution = $this->institutionName();
        $prepared = $this->prepare($template, $data);

        return [
            'institution_name' => $institution,
            'subject' => $this->subject($template, $data),
            'greeting' => $prepared['greeting'],
            'lines' => $prepared['lines'],
            'message_text' => $prepared['message_text'],
            'message_html' => $prepared['message_html'],
            'instruction' => $prepared['instruction'],
            'action_label' => $prepared['action_label'],
            'action_url' => $this->actionUrl($template, $data),
            'footer' => 'This message was sent by '.$institution.'.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     greeting: string,
     *     lines: list<array{label: string, value: string}>,
     *     message_text: string,
     *     message_html: string,
     *     instruction: string,
     *     action_label: string
     * }
     */
    private function prepare(EmailTemplate $template, array $data): array
    {
        $empty = [
            'greeting' => '',
            'lines' => [],
            'message_text' => '',
            'message_html' => '',
            'instruction' => '',
            'action_label' => '',
        ];

        return match ($template) {
            EmailTemplate::Credentials => $this->credentials($data, $empty),
            EmailTemplate::PasswordReset => $this->passwordReset($data, $empty),
            EmailTemplate::Admission => $this->admission($data, $empty),
            EmailTemplate::RegistrationConfirmed => $this->registration($data, $empty),
            EmailTemplate::PaymentConfirmed => $this->payment($data, $empty),
            EmailTemplate::ResultPublished => $this->result($data, $empty),
            EmailTemplate::Announcement => $this->announcement($data, $empty),
            EmailTemplate::TimetablePublished => $this->timetable($data, $empty),
            EmailTemplate::Test => throw new InvalidArgumentException(MailError::UNKNOWN_TEMPLATE),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}  $base
     * @return array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}
     */
    private function credentials(array $data, array $base): array
    {
        $name = $this->text($data, 'name');
        $expires = $this->formatDate($data['expires_at'] ?? null);
        $base['greeting'] = 'Hello '.$name.',';
        $base['lines'] = [
            ['label' => 'Sign in with', 'value' => $this->text($data, 'login_id')],
            ['label' => 'Temporary password', 'value' => $this->secretText($data, 'temporary_password')],
        ];
        $base['instruction'] = 'Change this password the first time you sign in; it expires on '.$expires.'.';
        $base['action_label'] = 'Sign in';

        return $base;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}  $base
     * @return array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}
     */
    private function passwordReset(array $data, array $base): array
    {
        $base['greeting'] = 'Hello '.$this->text($data, 'name').',';
        $base['instruction'] = 'Choose a new password with this link; it expires in 60 minutes.';
        $base['action_label'] = 'Choose a new password';

        return $base;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}  $base
     * @return array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}
     */
    private function admission(array $data, array $base): array
    {
        $programme = $this->text($data, 'programme');
        $base['greeting'] = 'Hello '.$this->text($data, 'name').',';
        $base['instruction'] = 'You have been admitted to '.$programme.', and we will email your sign-in details when your account is ready.';
        $base['action_label'] = 'Open the portal';

        return $base;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}  $base
     * @return array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}
     */
    private function registration(array $data, array $base): array
    {
        $units = $data['total_units'] ?? null;

        if (! is_int($units)) {
            throw new InvalidArgumentException(MailError::MISSING_TEMPLATE_DATA);
        }

        $base['greeting'] = 'Hello '.$this->text($data, 'name').',';
        $base['instruction'] = 'Your registration for '.$this->text($data, 'session_name').', '.$this->text($data, 'semester_name').' is confirmed with '.$units.' units.';
        $base['action_label'] = 'View your courses';

        return $base;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}  $base
     * @return array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}
     */
    private function payment(array $data, array $base): array
    {
        $amount = $data['amount_kobo'] ?? null;

        if (! is_int($amount)) {
            throw new InvalidArgumentException(MailError::MISSING_TEMPLATE_DATA);
        }

        $base['greeting'] = 'Hello '.$this->text($data, 'name').',';
        $base['instruction'] = 'We received '.Money::format($amount).' for receipt '.$this->text($data, 'receipt_number').' on '.$this->formatDate($data['paid_at'] ?? null).'.';
        $base['action_label'] = 'Sign in to view your receipt';

        return $base;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}  $base
     * @return array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}
     */
    private function result(array $data, array $base): array
    {
        $base['greeting'] = 'Hello '.$this->text($data, 'name').',';
        $base['instruction'] = 'Your result for '.$this->text($data, 'session_name').', '.$this->text($data, 'semester_name').' is published.';
        $base['action_label'] = 'View your result';

        return $base;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}  $base
     * @return array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}
     */
    private function announcement(array $data, array $base): array
    {
        $message = $this->text($data, 'message');
        $base['message_text'] = $message;
        $base['message_html'] = $this->messageHtml($message);
        $base['instruction'] = 'Read this announcement in the portal.';
        $base['action_label'] = 'Read it in the portal';

        return $base;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}  $base
     * @return array{greeting: string, lines: list<array{label: string, value: string}>, message_text: string, message_html: string, instruction: string, action_label: string}
     */
    private function timetable(array $data, array $base): array
    {
        $base['greeting'] = 'Hello '.$this->text($data, 'name').',';
        $base['instruction'] = 'The exam timetable for '.$this->text($data, 'session_name').', '.$this->text($data, 'semester_name').' is published.';
        $base['action_label'] = 'View the timetable';

        return $base;
    }

    /**
     * Escape the message, then turn newlines into HTML line breaks.
     */
    private function messageHtml(string $message): string
    {
        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return str_replace(["\r\n", "\r", "\n", "\u{2028}", "\u{2029}"], '<br>', $escaped);
    }

    /**
     * @param  array{
     *     institution_name: string,
     *     subject: string,
     *     greeting: string,
     *     lines: list<array{label: string, value: string}>,
     *     message_text: string,
     *     message_html: string,
     *     instruction: string,
     *     action_label: string,
     *     action_url: string,
     *     footer: string
     * }  $view
     */
    private function plainText(array $view): string
    {
        $blocks = [$view['institution_name']];

        if ($view['greeting'] !== '') {
            $blocks[] = $view['greeting'];
        }

        foreach ($view['lines'] as $line) {
            $blocks[] = $line['label'].': '.$line['value'];
        }

        if ($view['message_text'] !== '') {
            $blocks[] = $view['message_text'];
        }

        $blocks[] = $view['instruction'];
        $blocks[] = $view['action_label'].': '.$view['action_url'];
        $blocks[] = $view['footer'];

        return implode("\n\n", $blocks);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function actionUrl(EmailTemplate $template, array $data): string
    {
        $base = $this->baseUrl();

        return match ($template) {
            EmailTemplate::Credentials, EmailTemplate::PaymentConfirmed => $base.'/login',
            EmailTemplate::PasswordReset => $base.'/reset-password/'.rawurlencode($this->secretText($data, 'token')),
            EmailTemplate::Admission => $base.'/',
            EmailTemplate::RegistrationConfirmed, EmailTemplate::ResultPublished, EmailTemplate::Announcement, EmailTemplate::TimetablePublished => $base.'/student',
            EmailTemplate::Test => throw new InvalidArgumentException(MailError::UNKNOWN_TEMPLATE),
        };
    }

    private function baseUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $allowed = preg_match('#\Ahttps?://#', $base) === 1 && preg_match('/[\s\x00]/', $base) !== 1;

        if ($base === '' || ! $allowed) {
            throw new InvalidArgumentException(MailError::INVALID_APPLICATION_URL);
        }

        return $base;
    }

    private function institutionName(): string
    {
        $name = config('portal.institution.name');

        if (! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException(MailError::INVALID_TEMPLATE_DATA);
        }

        return trim($name);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(MailError::MISSING_TEMPLATE_DATA);
        }

        return trim($value);
    }

    /**
     * Keep a secret exactly as supplied once it is not blank.
     *
     * @param  array<string, mixed>  $data
     */
    private function secretText(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(MailError::MISSING_TEMPLATE_DATA);
        }

        return $value;
    }

    private function formatDate(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(MailError::MISSING_TEMPLATE_DATA);
        }

        $formatted = Dates::dateTime(trim($value));

        if ($formatted === self::EMPTY_DATE) {
            throw new InvalidArgumentException(MailError::INVALID_TEMPLATE_DATA);
        }

        return $formatted;
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleData(EmailTemplate $template): array
    {
        return match ($template) {
            EmailTemplate::Credentials => [
                'name' => 'Ada Lovelace',
                'login_id' => 'CSC/2026/0001',
                'temporary_password' => 'Preview-pass-91',
                'expires_at' => '2026-10-17 14:30:00',
            ],
            EmailTemplate::PasswordReset => [
                'name' => 'Ada Lovelace',
                'token' => 'preview-token',
            ],
            EmailTemplate::Admission => [
                'name' => 'Ada Lovelace',
                'programme' => 'BSc Computer Science',
            ],
            EmailTemplate::RegistrationConfirmed => [
                'name' => 'Ada Lovelace',
                'session_name' => '2026/2027',
                'semester_name' => 'First semester',
                'total_units' => 18,
            ],
            EmailTemplate::PaymentConfirmed => [
                'name' => 'Ada Lovelace',
                'amount_kobo' => 5_000_000,
                'receipt_number' => 'RCT-2026-0001',
                'paid_at' => '2026-10-10 09:15:00',
            ],
            EmailTemplate::ResultPublished => [
                'name' => 'Ada Lovelace',
                'session_name' => '2026/2027',
                'semester_name' => 'First semester',
            ],
            EmailTemplate::Announcement => [
                'title' => 'Library hours',
                'message' => 'The library is open from 8:00 to 18:00.',
            ],
            EmailTemplate::TimetablePublished => [
                'name' => 'Ada Lovelace',
                'session_name' => '2026/2027',
                'semester_name' => 'First semester',
            ],
            EmailTemplate::Test => throw new InvalidArgumentException(MailError::UNKNOWN_TEMPLATE),
        };
    }
}
