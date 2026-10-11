<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Filament\Staff\Pages\EmailOutboxPage;
use App\Jobs\SendOutboxEmail;
use App\Models\AuditLog;
use App\Models\EmailOutbox;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MailService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'mail.daily_limit' => null,
        'mail.default' => 'array',
    ]);
    Cache::store('database')->forget(MailService::DAILY_LIMIT_KEY);
    Cache::store('database')->forget(MailService::CIRCUIT_PAUSE_KEY);
});

/**
 * Whether the needle appears. A failure of the boolean does not print it.
 */
/**
 * Page HTML plus any action-modal partials from the last Livewire render.
 */
function outbox_rendered(Testable $page): string
{
    $html = $page->html();
    $effects = $page->effects;
    $partials = is_array($effects) ? ($effects['partials'] ?? []) : [];

    if (! is_array($partials)) {
        return $html;
    }

    foreach ($partials as $partial) {
        if (is_string($partial)) {
            $html .= $partial;
        }
    }

    return $html;
}

function outbox_page_has(string $haystack, string $needle): bool
{
    return $needle !== '' && str_contains($haystack, $needle);
}

/**
 * An active user holding exactly this role.
 */
function outbox_user_for_role(Role $role): User
{
    if ($role === Role::Student) {
        $student = Student::factory()->create();

        return User::query()->findOrFail($student->user_id);
    }

    $user = User::factory()->create();

    if ($role === Role::FacultyAdmin) {
        RoleAssignment::factory()->facultyAdmin()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    if ($role === Role::DepartmentOfficer) {
        RoleAssignment::factory()->departmentOfficer()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
        'faculty_id' => null,
        'department_id' => null,
    ]);

    return $user;
}

/**
 * A failed secret row whose stored body contains the secret.
 */
function outbox_failed_secret(string $template, string $secret): EmailOutbox
{
    return EmailOutbox::factory()->create([
        'template' => $template,
        'status' => EmailStatus::Failed,
        'subject' => 'Portal message',
        'body_html' => $secret,
        'body_text' => $secret,
        'secrets' => ['temporary_password' => $secret],
        'attempts' => 1,
        'last_error' => 'provider_unavailable',
        'sent_at' => null,
        'redacted_at' => null,
    ]);
}

/**
 * Run a forged call. A 403, an authorization failure, or a component that
 * never mounted counts as a refusal. Any other failure is rethrown.
 */
function outbox_attempt(callable $call): void
{
    try {
        $call();
    } catch (HttpException $exception) {
        if ($exception->getStatusCode() !== 403) {
            throw $exception;
        }
    } catch (AuthorizationException) {
        return;
    } catch (Throwable $exception) {
        if (! str_contains($exception->getMessage(), 'mountedActions')) {
            throw $exception;
        }
    }
}

/**
 * Mount a table action and run it, skipping the test helper's visibility check.
 */
function outbox_forge_table_action(string $name, EmailOutbox $row): void
{
    $page = Livewire::test(EmailOutboxPage::class);
    $page->call('mountTableAction', $name, (string) $row->getKey());
    $page->call('callMountedAction');
}

/**
 * Mount a header action and run it.
 */
function outbox_forge_header_action(string $name): void
{
    $page = Livewire::test(EmailOutboxPage::class);
    $page->call('mountAction', $name);
    $page->call('callMountedAction');
}

it('lets an active super admin open the outbox and shows the daily limit', function () {
    $admin = outbox_user_for_role(Role::SuperAdmin);
    Cache::store('database')->put(MailService::DAILY_LIMIT_KEY, true, 60);

    $response = $this->actingAs($admin)->get('/staff/email-outbox');

    $response->assertOk();
    expect(outbox_page_has($response->getContent(), 'The daily send limit was reached. Further mail stays queued.'))->toBeTrue()
        ->and(outbox_page_has($response->getContent(), 'Send now is allowed for queued credentials and password reset rows. It reveals nothing.'))->toBeTrue()
        ->and(outbox_page_has($response->getContent(), 'Retry is not offered for those two templates.'))->toBeTrue();
});

it('refuses the outbox page and every action for other roles', function (Role $role) {
    $user = outbox_user_for_role($role);
    $row = EmailOutbox::factory()->failed()->create([
        'template' => 'announcement',
        'body_html' => '<p>Notice</p>',
        'body_text' => 'Notice',
    ]);

    $this->actingAs($user);

    if ($role === Role::Student) {
        $this->get('/staff/email-outbox')->assertRedirect('/student');
    } else {
        $this->get('/staff/email-outbox')->assertForbidden();
    }

    outbox_attempt(fn () => outbox_forge_table_action('retry', $row));
    outbox_attempt(fn () => outbox_forge_table_action('sendNow', $row));
    outbox_attempt(fn () => outbox_forge_header_action('sendTest'));

    expect($row->refresh()->status)->toBe(EmailStatus::Failed)
        ->and(AuditLog::query()->whereIn('action', [
            AuditService::ACTION_RETRIED,
            AuditService::ACTION_SENT_NOW,
            AuditService::ACTION_TEST_QUEUED,
        ])->count())->toBe(0);
})->with([
    Role::Registrar,
    Role::Bursar,
    Role::FacultyAdmin,
    Role::DepartmentOfficer,
    Role::Lecturer,
    Role::ExamOfficer,
    Role::Student,
]);

it('refuses a deactivated super admin, including forged actions', function () {
    $admin = outbox_user_for_role(Role::SuperAdmin);
    $admin->status = UserStatus::Deactivated;
    $admin->save();
    $row = EmailOutbox::factory()->failed()->create([
        'template' => 'announcement',
    ]);

    $this->actingAs($admin)->get('/staff/email-outbox')->assertRedirect('/login');

    outbox_attempt(fn () => outbox_forge_table_action('retry', $row));
    outbox_attempt(fn () => outbox_forge_table_action('sendNow', $row));
    outbox_attempt(fn () => outbox_forge_header_action('sendTest'));

    expect($row->refresh()->status)->toBe(EmailStatus::Failed)
        ->and(AuditLog::query()->whereIn('action', [
            AuditService::ACTION_RETRIED,
            AuditService::ACTION_SENT_NOW,
            AuditService::ACTION_TEST_QUEUED,
        ])->count())->toBe(0);
});

it('hides a failed credentials body and refuses retry', function () {
    Queue::fake();
    $admin = outbox_user_for_role(Role::SuperAdmin);
    $secret = 'Hidden-credentials-91';
    $row = outbox_failed_secret('credentials', $secret);

    $this->actingAs($admin);
    $page = Livewire::test(EmailOutboxPage::class);
    $page->assertTableActionHidden('retry', $row);
    $html = outbox_rendered($page->mountTableAction('view', $row));
    $leaked = outbox_page_has($html, $secret);
    outbox_attempt(fn () => outbox_forge_table_action('retry', $row));

    expect($leaked)->toBeFalse()
        ->and(outbox_page_has($html, 'Content hidden for security'))->toBeTrue()
        ->and($row->refresh()->status)->toBe(EmailStatus::Failed)
        ->and(AuditLog::query()->where('action', AuditService::ACTION_RETRIED)->count())->toBe(0);
});

it('hides a failed reset body and refuses retry', function () {
    $admin = outbox_user_for_role(Role::SuperAdmin);
    $secret = 'Hidden-reset-token-91';
    $row = outbox_failed_secret('password_reset', $secret);

    $this->actingAs($admin);
    $html = outbox_rendered(Livewire::test(EmailOutboxPage::class)->mountTableAction('view', $row));
    $leaked = outbox_page_has($html, $secret);
    Livewire::test(EmailOutboxPage::class)->assertTableActionHidden('retry', $row);
    outbox_attempt(fn () => outbox_forge_table_action('retry', $row));

    expect($leaked)->toBeFalse()
        ->and(outbox_page_has($html, 'Content hidden for security'))->toBeTrue()
        ->and($row->refresh()->status)->toBe(EmailStatus::Failed)
        ->and(AuditLog::query()->where('action', AuditService::ACTION_RETRIED)->count())->toBe(0);
});

it('sends a queued credentials row now without revealing the body', function () {
    Queue::fake();
    $admin = outbox_user_for_role(Role::SuperAdmin);
    $secret = 'Send-now-secret-91';
    $row = EmailOutbox::factory()->create([
        'template' => 'credentials',
        'status' => EmailStatus::Queued,
        'attempts' => 0,
        'body_html' => $secret,
        'body_text' => $secret,
        'secrets' => ['temporary_password' => $secret],
    ]);

    $this->actingAs($admin);
    Livewire::test(EmailOutboxPage::class)
        ->assertTableActionVisible('sendNow', $row)
        ->callTableAction('sendNow', $row);

    $html = outbox_rendered(Livewire::test(EmailOutboxPage::class)->mountTableAction('view', $row));
    $audit = AuditLog::query()->where('action', AuditService::ACTION_SENT_NOW)->first();
    $leaked = outbox_page_has($html, $secret)
        || outbox_page_has((string) json_encode($audit?->before), $secret)
        || outbox_page_has((string) json_encode($audit?->after), $secret);

    expect($leaked)->toBeFalse()
        ->and($row->refresh()->status)->toBe(EmailStatus::Queued)
        ->and($audit)->not->toBeNull()
        ->and($audit->after)->toBe([
            'status' => 'Queued',
            'template' => 'credentials',
        ])
        ->and(Queue::pushed(SendOutboxEmail::class))->toHaveCount(1);
});

it('sends a queued reset row now and writes an audit row', function () {
    Queue::fake();
    $admin = outbox_user_for_role(Role::SuperAdmin);
    $secret = 'Send-now-reset-91';
    $row = EmailOutbox::factory()->create([
        'template' => 'password_reset',
        'status' => EmailStatus::Queued,
        'attempts' => 0,
        'body_html' => $secret,
        'body_text' => $secret,
        'secrets' => ['token' => $secret],
    ]);

    $this->actingAs($admin);
    Livewire::test(EmailOutboxPage::class)->callTableAction('sendNow', $row);

    $html = Livewire::test(EmailOutboxPage::class)->html();
    $leaked = outbox_page_has($html, $secret);
    $audit = AuditLog::query()->where('action', AuditService::ACTION_SENT_NOW)->first();

    expect($leaked)->toBeFalse()
        ->and($audit)->not->toBeNull()
        ->and($audit->entity)->toBe(AuditService::ENTITY_EMAIL_OUTBOX)
        ->and($audit->entity_id)->toBe($row->id);
});

it('retries a failed row and writes an audit row', function () {
    Queue::fake();
    $admin = outbox_user_for_role(Role::SuperAdmin);
    $row = EmailOutbox::factory()->failed()->create([
        'template' => 'announcement',
        'body_text' => 'Campus notice',
        'attempts' => 3,
    ]);

    $this->actingAs($admin);
    Livewire::test(EmailOutboxPage::class)->callTableAction('retry', $row);

    $audit = AuditLog::query()->where('action', AuditService::ACTION_RETRIED)->first();

    expect($row->refresh()->status)->toBe(EmailStatus::Queued)
        ->and($row->attempts)->toBe(0)
        ->and($row->last_error)->toBeNull()
        ->and($audit)->not->toBeNull()
        ->and($audit->before)->toBe([
            'status' => 'Failed',
            'template' => 'announcement',
        ])
        ->and($audit->after)->toBe([
            'status' => 'Queued',
            'template' => 'announcement',
        ]);
});

it('queues a test email only to the signed-in admin', function () {
    Queue::fake();
    $admin = outbox_user_for_role(Role::SuperAdmin);
    $other = 'other-person@example.test';

    $this->actingAs($admin);
    Livewire::test(EmailOutboxPage::class)->callAction('sendTest');
    Livewire::test(EmailOutboxPage::class)->callAction('sendTest');

    $rows = EmailOutbox::query()->where('template', 'test')->get();
    $audit = AuditLog::query()->where('action', AuditService::ACTION_TEST_QUEUED)->first();
    $wrong_recipient = $rows->contains(fn (EmailOutbox $row): bool => $row->recipient_email === $other);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->recipient_email)->toBe($admin->email)
        ->and($wrong_recipient)->toBeFalse()
        ->and($audit)->not->toBeNull()
        ->and($audit->after)->toBe([
            'status' => 'Queued',
            'template' => 'test',
        ]);
});

it('shows another template body', function () {
    $admin = outbox_user_for_role(Role::SuperAdmin);
    $row = EmailOutbox::factory()->create([
        'template' => 'announcement',
        'body_text' => 'Campus notice text',
        'body_html' => '<p>Campus notice text</p>',
    ]);

    $this->actingAs($admin);
    $html = outbox_rendered(Livewire::test(EmailOutboxPage::class)->mountTableAction('view', $row));

    expect(outbox_page_has($html, 'Campus notice text'))->toBeTrue()
        ->and(outbox_page_has($html, 'Content hidden for security'))->toBeFalse();
});
