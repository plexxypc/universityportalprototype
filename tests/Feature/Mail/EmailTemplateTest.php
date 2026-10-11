<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Enums\EmailTemplate;
use App\Models\EmailOutbox;
use App\Services\MailService;
use App\Support\Mail\EmailTemplateRenderer;
use App\Support\Mail\MailError;
use App\Support\Mail\RenderedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

/**
 * Sample data whose values contain no placeholder markers.
 *
 * @return array<string, mixed>
 */
function email_template_sentinel(EmailTemplate $template): array
{
    return match ($template) {
        EmailTemplate::Credentials => [
            'name' => 'NameSentinel',
            'login_id' => 'LoginSentinel',
            'temporary_password' => 'PasswordSentinel',
            'expires_at' => '2026-10-17 14:30:00',
        ],
        EmailTemplate::PasswordReset => [
            'name' => 'NameSentinel',
            'token' => 'TokenSentinel',
        ],
        EmailTemplate::Admission => [
            'name' => 'NameSentinel',
            'programme' => 'ProgrammeSentinel',
        ],
        EmailTemplate::RegistrationConfirmed => [
            'name' => 'NameSentinel',
            'session_name' => 'SessionSentinel',
            'semester_name' => 'SemesterSentinel',
            'total_units' => 18,
        ],
        EmailTemplate::PaymentConfirmed => [
            'name' => 'NameSentinel',
            'amount_kobo' => 5_000_000,
            'receipt_number' => 'ReceiptSentinel',
            'paid_at' => '2026-10-10 09:15:00',
        ],
        EmailTemplate::ResultPublished, EmailTemplate::TimetablePublished => [
            'name' => 'NameSentinel',
            'session_name' => 'SessionSentinel',
            'semester_name' => 'SemesterSentinel',
        ],
        EmailTemplate::Announcement => [
            'title' => 'TitleSentinel',
            'message' => 'MessageSentinel',
        ],
        EmailTemplate::Test => [
            'message' => 'MessageSentinel',
        ],
    };
}

/**
 * Href values from one HTML part.
 *
 * @return list<string>
 */
function email_template_hrefs(string $html): array
{
    preg_match_all('/href="([^"]*)"/', $html, $matches);

    return array_map(
        static fn (string $href): string => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        $matches[1],
    );
}

/**
 * Absolute URLs in one plain-text part.
 *
 * @return list<string>
 */
function email_template_text_links(string $text): array
{
    preg_match_all('#https?://[^\s<]+#', $text, $matches);

    return $matches[0];
}

/**
 * Render one branded template with the sentinel payload.
 */
function email_template_render(EmailTemplate $template): RenderedMail
{
    return app(EmailTemplateRenderer::class)->render($template, email_template_sentinel($template));
}

it('allows only the named email templates', function () {
    $names = array_map(
        static fn (EmailTemplate $template): string => $template->value,
        EmailTemplate::cases(),
    );

    expect($names)->toBe([
        'credentials',
        'password_reset',
        'admission',
        'registration_confirmed',
        'payment_confirmed',
        'result_published',
        'announcement',
        'timetable_published',
        'test',
    ])
        ->and(EmailTemplate::Credentials->secretKeys())->toBe(['temporary_password'])
        ->and(EmailTemplate::PasswordReset->secretKeys())->toBe(['token']);

    $matched = false;

    try {
        app(MailService::class)->send('not_a_template', 'ada@example.com', []);
    } catch (InvalidArgumentException $exception) {
        $matched = $exception->getMessage() === MailError::UNKNOWN_TEMPLATE;
    }

    expect($matched)->toBeTrue();
});

it('leaves no placeholder in the template sources or a sentinel render', function () {
    $files = File::allFiles(resource_path('views/emails'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $source = $file->getContents();
        $has_token = str_contains($source, ':placeholder');
        $raw_mustache = preg_match('/\{\{(?!\s*\$)/', $source) === 1;
        $raw_echo = preg_match('/\{!!(?!\s*\$message_html\s*!!)/', $source) === 1;
        $image = stripos($source, '<img') !== false;

        expect($has_token)->toBeFalse()
            ->and($raw_mustache)->toBeFalse()
            ->and($raw_echo)->toBeFalse()
            ->and($image)->toBeFalse();
    }

    config([
        'app.url' => 'https://portal.test',
        'portal.institution.name' => 'Sentinel <University>',
    ]);
    URL::forceRootUrl('https://evil.example');
    request()->headers->set('HOST', 'evil.example');

    foreach (EmailTemplate::cases() as $template) {
        if ($template === EmailTemplate::Test) {
            continue;
        }

        $rendered = email_template_render($template);
        $html_marker = str_contains($rendered->html, '{{')
            || str_contains($rendered->html, '}}')
            || str_contains($rendered->html, ':placeholder');
        $text_marker = str_contains($rendered->text, '{{')
            || str_contains($rendered->text, '}}')
            || str_contains($rendered->text, ':placeholder');
        $raw_name = str_contains($rendered->html, '<University>');
        $escaped_name = str_contains($rendered->html, '&lt;University&gt;');
        $image = stripos($rendered->html, '<img') !== false;
        $uses_config_url = str_contains($rendered->html, 'https://portal.test')
            && str_contains($rendered->text, 'https://portal.test');
        $uses_request_host = str_contains($rendered->html, 'evil.example')
            || str_contains($rendered->text, 'evil.example');
        $hrefs = email_template_hrefs($rendered->html);
        $text_links = email_template_text_links($rendered->text);
        $blocks = preg_split("/\n\n/", trim($rendered->text));
        $instruction = is_array($blocks) ? (string) ($blocks[count($blocks) - 3] ?? '') : '';
        $instruction_in_html = $instruction !== '' && str_contains($rendered->html, $instruction);

        expect($html_marker)->toBeFalse()
            ->and($text_marker)->toBeFalse()
            ->and($raw_name)->toBeFalse()
            ->and($escaped_name)->toBeTrue()
            ->and($image)->toBeFalse()
            ->and($uses_config_url)->toBeTrue()
            ->and($uses_request_host)->toBeFalse()
            ->and($hrefs)->toBe($text_links)
            ->and($hrefs)->not->toBeEmpty()
            ->and($instruction_in_html)->toBeTrue();
    }
});

it('keeps an announcement message that contains braces and escapes its html', function () {
    Queue::fake();

    $message = "Venue:Hall B\nRe:exam\n{{ x }}\n<b>Hi</b>";
    $row = app(MailService::class)->send(EmailTemplate::Announcement->value, 'ada@example.com', [
        'title' => 'Exam venues',
        'message' => $message,
    ]);

    $html = $row->body_html;
    $text = $row->body_text;
    $raw_tag = str_contains($html, '<b>');
    $escaped = str_contains($html, '&lt;b&gt;') && str_contains($html, '&lt;/b&gt;');
    $has_break = str_contains($html, '<br>');
    $kept_venue = str_contains($html, 'Venue:Hall B') && str_contains($html, 'Re:exam') && str_contains($html, '{{ x }}');
    $text_keeps_newlines = str_contains($text, $message);
    $text_has_break_tag = str_contains($text, '<br>');
    $instruction = 'Read this announcement in the portal.';
    $same_instruction = str_contains($html, $instruction) && str_contains($text, $instruction);
    $hrefs = email_template_hrefs($html);
    $text_links = email_template_text_links($text);

    expect($row->status)->toBe(EmailStatus::Queued)
        ->and($raw_tag)->toBeFalse()
        ->and($escaped)->toBeTrue()
        ->and($has_break)->toBeTrue()
        ->and($kept_venue)->toBeTrue()
        ->and($text_keeps_newlines)->toBeTrue()
        ->and($text_has_break_tag)->toBeFalse()
        ->and($same_instruction)->toBeTrue()
        ->and($hrefs)->toBe($text_links);
});

it('renders a staff credentials email from an email login id', function () {
    Queue::fake();

    $password = 'Staff-secret-91';
    $login_id = 'lecturer@example.com';
    $data = [
        'name' => 'Ada Lovelace',
        'login_id' => $login_id,
        'temporary_password' => $password,
        'expires_at' => '2026-10-17 14:30:00',
    ];
    $rendered = app(EmailTemplateRenderer::class)->render(EmailTemplate::Credentials, $data);
    $subject = app(EmailTemplateRenderer::class)->fixedSubject(EmailTemplate::Credentials);
    $shows_password = str_contains($rendered->html, $password) && str_contains($rendered->text, $password);
    $shows_login = str_contains($rendered->html, 'Sign in with')
        && str_contains($rendered->html, $login_id)
        && str_contains($rendered->text, 'Sign in with')
        && str_contains($rendered->text, $login_id);
    $subject_exact = $subject === 'Your university portal account';
    $subject_leaks = str_contains($subject, $password);

    $row = app(MailService::class)->send(EmailTemplate::Credentials->value, 'lecturer@example.com', $data);
    $holds_login = is_array($row->secrets) && ($row->secrets['login_id'] ?? null) === $login_id;
    $holds_password = is_array($row->secrets) && ($row->secrets['temporary_password'] ?? null) === $password;
    $stored_leaks = str_contains($row->body_html, $password) || str_contains($row->body_text, $password);
    $row_subject_exact = $row->subject === 'Your university portal account';
    $row_subject_leaks = str_contains($row->subject, $password);

    expect($shows_password)->toBeTrue()
        ->and($shows_login)->toBeTrue()
        ->and($subject_exact)->toBeTrue()
        ->and($subject_leaks)->toBeFalse()
        ->and($holds_login)->toBeTrue()
        ->and($holds_password)->toBeTrue()
        ->and($stored_leaks)->toBeFalse()
        ->and($row_subject_exact)->toBeTrue()
        ->and($row_subject_leaks)->toBeFalse();
});

it('refuses a blank required key and accepts zero', function () {
    Queue::fake();

    $password = 'Blank-secret-91';
    $base = [
        'name' => 'Ada Lovelace',
        'login_id' => 'CSC/2026/0001',
        'temporary_password' => $password,
        'expires_at' => '2026-10-17 14:30:00',
    ];

    foreach ([null, '', '   ', " \n "] as $name) {
        $data = $base;
        $data['name'] = $name;
        $matched = false;
        $leaked = false;

        try {
            app(MailService::class)->send(EmailTemplate::Credentials->value, 'ada@example.com', $data);
        } catch (InvalidArgumentException $exception) {
            $matched = $exception->getMessage() === MailError::MISSING_TEMPLATE_DATA;
            $leaked = str_contains($exception->getMessage(), $password);
        }

        expect($matched)->toBeTrue()->and($leaked)->toBeFalse();
    }

    $missing = $base;
    unset($missing['login_id']);
    $missing_matched = false;

    try {
        app(MailService::class)->send(EmailTemplate::Credentials->value, 'ada@example.com', $missing);
    } catch (InvalidArgumentException $exception) {
        $missing_matched = $exception->getMessage() === MailError::MISSING_TEMPLATE_DATA;
    }

    $string_zero = [
        'name' => 'Ada Lovelace',
        'session_name' => '2026/2027',
        'semester_name' => 'First semester',
        'total_units' => '0',
    ];
    $string_zero_matched = false;

    try {
        app(MailService::class)->send(EmailTemplate::RegistrationConfirmed->value, 'ada@example.com', $string_zero);
    } catch (InvalidArgumentException $exception) {
        $string_zero_matched = $exception->getMessage() === MailError::MISSING_TEMPLATE_DATA;
    }

    $registration = app(MailService::class)->send(EmailTemplate::RegistrationConfirmed->value, 'ada@example.com', [
        'name' => 'Ada Lovelace',
        'session_name' => '2026/2027',
        'semester_name' => 'First semester',
        'total_units' => 0,
    ]);
    $payment = app(MailService::class)->send(EmailTemplate::PaymentConfirmed->value, 'ada@example.com', [
        'name' => 'Ada Lovelace',
        'amount_kobo' => 0,
        'receipt_number' => 'RCT-0',
        'paid_at' => '2026-10-10 09:15:00',
    ]);
    $zero_units = str_contains($registration->body_html, 'with 0 units')
        && str_contains($registration->body_text, 'with 0 units');
    $zero_amount = str_contains($payment->body_html, '₦0.00')
        && str_contains($payment->body_text, '₦0.00');

    expect($missing_matched)->toBeTrue()
        ->and($string_zero_matched)->toBeTrue()
        ->and(EmailOutbox::query()->count())->toBe(2)
        ->and($zero_units)->toBeTrue()
        ->and($zero_amount)->toBeTrue();
});

it('cleans an announcement subject and refuses an empty one', function () {
    Queue::fake();

    $broken = app(MailService::class)->send(EmailTemplate::Announcement->value, 'ada@example.com', [
        'title' => "Hello\r\nthere\u{2028}x\u{2029}y<script>",
        'message' => 'The hall is open.',
    ]);
    $has_break = str_contains($broken->subject, "\r")
        || str_contains($broken->subject, "\n")
        || str_contains($broken->subject, "\u{2028}")
        || str_contains($broken->subject, "\u{2029}");

    $long = app(MailService::class)->send(EmailTemplate::Announcement->value, 'ada@example.com', [
        'title' => str_repeat('é', 151),
        'message' => 'The hall is open.',
    ]);
    $capped = mb_strlen($long->subject, 'UTF-8') === 150 && $long->subject === str_repeat('é', 150);

    $refused = false;

    try {
        app(MailService::class)->send(EmailTemplate::Announcement->value, 'ada@example.com', [
            'title' => "\u{2028}\u{2029}",
            'message' => 'The hall is open.',
        ]);
    } catch (InvalidArgumentException $exception) {
        $refused = $exception->getMessage() === MailError::INVALID_SUBJECT;
    }

    expect($broken->subject)->toBe('Hellotherexy<script>')
        ->and($has_break)->toBeFalse()
        ->and($capped)->toBeTrue()
        ->and($refused)->toBeTrue()
        ->and(EmailOutbox::query()->count())->toBe(2);
});

it('hides the email preview outside the local environment', function () {
    expect(Route::has('design-preview.emails'))->toBeFalse();

    $this->get('/design-preview/emails')->assertNotFound();
});
