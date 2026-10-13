<?php

declare(strict_types=1);

use App\Enums\EmailStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Filament\Staff\Pages\EmailOutboxPage;
use App\Jobs\SendOutboxEmail;
use App\Models\AcademicSession;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\EmailOutbox;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\CredentialDelivery;
use App\Services\CredentialService;
use App\Services\MailService;
use App\Services\StudentService;
use App\Support\Mail\MailError;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config([
        'queue.default' => 'database',
        'mail.default' => 'array',
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function credential_input(Programme $programme, array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'other_names' => 'Mary',
        'email' => 'ada.lovelace@example.com',
        'phone' => '08012345678',
        'gender' => 'Female',
        'date_of_birth' => '2004-05-01',
        'state_of_origin' => 'Lagos',
        'address' => '12 Garden Close',
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => null,
    ], $overrides);
}

function credential_actor(Role $role = Role::Registrar): User
{
    $user = User::factory()->create();

    if ($role === Role::FacultyAdmin) {
        RoleAssignment::factory()->facultyAdmin()->create([
            'user_id' => $user->id,
        ]);

        return $user;
    }

    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

function credential_request(): Request
{
    return Request::create('/staff/students', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.20',
    ]);
}

function credential_programme(string $code = 'CSC'): Programme
{
    $department = Department::factory()->create([
        'code' => $code,
        'name' => $code.' Department',
    ]);

    return Programme::factory()->create([
        'department_id' => $department->id,
        'code' => $code,
        'name' => $code.' Programme',
        'duration_years' => 4,
    ]);
}

function credential_session(): AcademicSession
{
    $current = AcademicSession::query()->where('is_current', true)->first();

    if ($current instanceof AcademicSession) {
        return $current;
    }

    return AcademicSession::factory()->current()->create([
        'name' => '2026/2027',
    ]);
}

/**
 * Create one student and keep the plaintext only in this process.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{actor: User, student: Student, row: EmailOutbox, password: string, state: string}
 */
function credential_issue(array $overrides = []): array
{
    $actor = credential_actor();
    $created = app(StudentService::class)->create(
        $actor,
        credential_input(credential_programme(), $overrides),
        credential_request(),
    );
    $row = EmailOutbox::query()
        ->where('user_id', $created->student->user_id)
        ->where('template', 'credentials')
        ->latest('id')
        ->first();

    if (! $row instanceof EmailOutbox) {
        throw new RuntimeException('credentials row missing');
    }

    return [
        'actor' => $actor,
        'student' => $created->student,
        'row' => $row,
        'password' => credential_plain($row),
        'state' => $created->delivery->state,
    ];
}

/**
 * The password from the encrypted payload. Callers must not print it.
 */
function credential_plain(EmailOutbox $row): string
{
    $secrets = $row->secrets;
    $password = is_array($secrets) ? ($secrets['temporary_password'] ?? '') : '';

    return is_string($password) ? $password : '';
}

/**
 * @return array<string, array<int, string>>
 */
function credential_errors(callable $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

/**
 * Whether the needle appears. A failure of the boolean does not print it.
 */
function credential_leaked(string $haystack, string $needle): bool
{
    return $needle !== '' && str_contains($haystack, $needle);
}

/**
 * @param  list<string>  $columns
 */
function credential_columns(string $table, array $columns): string
{
    return DB::table($table)->get()->map(function (object $row) use ($columns): string {
        $parts = [];

        foreach ($columns as $column) {
            $value = $row->{$column} ?? '';
            $parts[] = is_scalar($value) || $value === null ? (string) $value : '';
        }

        return implode(' ', $parts);
    })->implode("\n");
}

it('sets the password flag and the expiry together', function () {
    credential_session();
    $issued = credential_issue();
    $user = $issued['student']->user()->first();
    $expires = $user?->temp_password_expires_at;
    $close = $expires !== null && abs($expires->diffInSeconds(now()->addDays(7))) < 120;

    expect($user)->not->toBeNull()
        ->and($user?->must_change_password)->toBeTrue()
        ->and($expires)->not->toBeNull()
        ->and($close)->toBeTrue()
        ->and($issued['state'])->toBe(CredentialDelivery::QUEUED);
});

it('stores only the password hash', function () {
    credential_session();
    $issued = credential_issue();
    $user = $issued['student']->user()->first();
    $plain = $issued['password'];
    $hash = (string) $user?->password;
    $matches = $user instanceof User && Hash::check($plain, $hash);
    $stored_plain = $hash === $plain;
    $length_ok = strlen($plain) === CredentialService::PASSWORD_LENGTH;
    $alphabet_ok = preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789]+$/', $plain) === 1;
    $mixed = preg_match('/\p{L}/u', $plain) === 1 && preg_match('/\d/', $plain) === 1;

    expect($matches)->toBeTrue()
        ->and($stored_plain)->toBeFalse()
        ->and($length_ok && $alphabet_ok && $mixed)->toBeTrue();
});

it('queues the credentials template with the matric number', function () {
    credential_session();
    $issued = credential_issue();
    $secrets = $issued['row']->secrets;
    $login_id = is_array($secrets) ? ($secrets['login_id'] ?? null) : null;

    expect($issued['row']->template)->toBe('credentials')
        ->and($issued['row']->status)->toBe(EmailStatus::Queued)
        ->and($login_id)->toBe($issued['student']->matric_no)
        ->and($issued['row']->body_text)->toBe('Message held until send.');
});

it('redacts the password after the welcome email is sent', function () {
    credential_session();
    $issued = credential_issue();
    $plain = $issued['password'];

    (new SendOutboxEmail($issued['row']->id))->handle(app(MailService::class));
    $issued['row']->refresh();
    $body = (string) $issued['row']->body_text.(string) $issued['row']->body_html;
    $leaked = credential_leaked($body, $plain);

    expect($issued['row']->status)->toBe(EmailStatus::Sent)
        ->and($issued['row']->secrets)->toBeNull()
        ->and($issued['row']->redacted_at)->not->toBeNull()
        ->and($leaked)->toBeFalse();
});

it('issues a new password on resend and supersedes the unsent row', function () {
    config(['session.driver' => 'database']);
    credential_session();
    $issued = credential_issue();
    $user = $issued['student']->user()->first();
    $old_plain = $issued['password'];
    $old_hash = (string) $user?->password;
    $remember_before = $user?->remember_token;

    DB::table('sessions')->insert([
        'id' => 'credential-resend-session',
        'user_id' => $user?->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'browser',
        'payload' => base64_encode('session'),
        'last_activity' => time(),
    ]);

    $delivery = app(CredentialService::class)->resend(
        $issued['actor'],
        $user instanceof User ? $user : throw new RuntimeException('user missing'),
        $issued['student']->matric_no,
        credential_request(),
    );
    $user?->refresh();
    $issued['row']->refresh();
    $replacement = EmailOutbox::query()
        ->where('user_id', $user?->id)
        ->where('status', EmailStatus::Queued)
        ->latest('id')
        ->first();
    $new_plain = $replacement instanceof EmailOutbox ? credential_plain($replacement) : '';
    $new_hash = (string) $user?->password;
    $hash_changed = $new_hash !== '' && $new_hash !== $old_hash;
    $plain_changed = $new_plain !== '' && $new_plain !== $old_plain;
    $audit = AuditLog::query()->where('action', CredentialService::ACTION_RESENT)->first();
    $encoded = json_encode($audit?->before).json_encode($audit?->after);
    $leaked = credential_leaked($encoded, $old_plain) || credential_leaked($encoded, $new_plain);
    $rotated = $remember_before !== null && $user?->remember_token !== $remember_before;

    expect($delivery->state)->toBe(CredentialDelivery::QUEUED)
        ->and($issued['row']->status)->toBe(EmailStatus::Failed)
        ->and($issued['row']->last_error)->toBe(MailError::SUPERSEDED)
        ->and($issued['row']->secrets)->toBeNull()
        ->and($hash_changed)->toBeTrue()
        ->and($plain_changed)->toBeTrue()
        ->and($user?->must_change_password)->toBeTrue()
        ->and(DB::table('sessions')->where('user_id', $user?->id)->exists())->toBeFalse()
        ->and($rotated)->toBeTrue()
        ->and($audit?->entity)->toBe('users')
        ->and($leaked)->toBeFalse();
});

it('refuses to resend for a user who is not active', function () {
    credential_session();
    $issued = credential_issue();
    $user = $issued['student']->user()->first();
    $user?->forceFill(['status' => UserStatus::Suspended])->save();
    $hash = (string) $user?->fresh()?->password;
    $errors = credential_errors(function () use ($issued, $user): void {
        if (! $user instanceof User) {
            throw new RuntimeException('user missing');
        }

        app(CredentialService::class)->resend(
            $issued['actor'],
            $user,
            $issued['student']->matric_no,
            credential_request(),
        );
    });
    $same = $user?->fresh()?->password === $hash;

    expect($errors['credentials'][0] ?? null)->toBe(CredentialService::MESSAGE_INACTIVE)
        ->and($same)->toBeTrue();
});

it('refuses to replace a chosen password without confirmation', function () {
    credential_session();
    $issued = credential_issue();
    $user = $issued['student']->user()->first();
    $user?->forceFill([
        'must_change_password' => false,
        'temp_password_expires_at' => null,
    ])->save();
    $hash = (string) $user?->fresh()?->password;
    $errors = credential_errors(function () use ($issued, $user): void {
        if (! $user instanceof User) {
            throw new RuntimeException('user missing');
        }

        app(CredentialService::class)->resend(
            $issued['actor'],
            $user,
            $issued['student']->matric_no,
            credential_request(),
            false,
        );
    });
    $same = $user?->fresh()?->password === $hash;
    $confirmed = false;

    if ($user instanceof User) {
        $confirmed = app(CredentialService::class)->resend(
            $issued['actor'],
            $user,
            $issued['student']->matric_no,
            credential_request(),
            true,
        )->state === CredentialDelivery::QUEUED;
    }

    $replaced = $user?->fresh()?->password !== $hash;

    expect($errors['credentials'][0] ?? null)->toBe(CredentialService::MESSAGE_REPLACE)
        ->and($same)->toBeTrue()
        ->and($confirmed)->toBeTrue()
        ->and($replaced)->toBeTrue()
        ->and($user?->fresh()?->must_change_password)->toBeTrue();
});

it('allows only one resend inside the cool-down', function () {
    credential_session();
    $issued = credential_issue();
    $user = $issued['student']->user()->first();

    if (! $user instanceof User) {
        throw new RuntimeException('user missing');
    }

    app(CredentialService::class)->resend(
        $issued['actor'],
        $user,
        $issued['student']->matric_no,
        credential_request(),
    );
    $hash = (string) $user->fresh()?->password;
    $errors = credential_errors(function () use ($issued, $user): void {
        app(CredentialService::class)->resend(
            $issued['actor'],
            $user,
            $issued['student']->matric_no,
            credential_request(),
        );
    });
    $same = $user->fresh()?->password === $hash;
    $resent = AuditLog::query()->where('action', CredentialService::ACTION_RESENT)->count();

    expect($errors['credentials'][0] ?? null)->toBe(CredentialService::MESSAGE_COOLDOWN)
        ->and($same)->toBeTrue()
        ->and($resent)->toBe(1);
});

it('lets the student sign in with the matric number and forces a password change', function () {
    credential_session();
    $issued = credential_issue();
    $plain = $issued['password'];
    $response = $this->post('/login', [
        'identifier' => $issued['student']->matric_no,
        'password' => $plain,
    ]);
    $location = (string) $response->headers->get('Location');
    $signed_in = str_ends_with($location, '/student') && auth()->check();
    $forced_location = (string) $this->get('/student')->headers->get('Location');
    $forced = str_ends_with($forced_location, '/change-password');

    expect($signed_in)->toBeTrue()
        ->and($forced)->toBeTrue();
});

it('blocks an expired temporary password and a resend allows sign-in', function () {
    credential_session();
    $issued = credential_issue();
    $user = $issued['student']->user()->first();
    $user?->forceFill(['temp_password_expires_at' => now()->subMinute()])->save();
    $blocked = $this->post('/login', [
        'identifier' => $issued['student']->matric_no,
        'password' => $issued['password'],
    ]);
    $blocked_location = (string) $blocked->headers->get('Location');

    expect(str_ends_with($blocked_location, '/student'))->toBeFalse()
        ->and(auth()->check())->toBeFalse();

    if (! $user instanceof User) {
        throw new RuntimeException('user missing');
    }

    app(CredentialService::class)->resend(
        $issued['actor'],
        $user,
        $issued['student']->matric_no,
        credential_request(),
    );
    $replacement = EmailOutbox::query()
        ->where('user_id', $user->id)
        ->where('status', EmailStatus::Queued)
        ->latest('id')
        ->first();
    $plain = $replacement instanceof EmailOutbox ? credential_plain($replacement) : '';
    $allowed = $this->post('/login', [
        'identifier' => $issued['student']->matric_no,
        'password' => $plain,
    ]);
    $location = (string) $allowed->headers->get('Location');

    expect(str_ends_with($location, '/student'))->toBeTrue()
        ->and(auth()->check())->toBeTrue();
});

it('keeps the temporary password out of logs, jobs, audit, cache, sessions, notifications, the outbox, the outbox page, and health', function () {
    $connection = DB::connection();
    $wrapped = $connection->transactionLevel() > 0;

    if ($wrapped) {
        $connection->rollBack();
    }

    $plain = '';

    try {
        config([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'session.driver' => 'database',
        ]);
        $logged = '';
        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $encoded = json_encode([$event->message, $event->context]);

            if (is_string($encoded)) {
                $logged .= $encoded;
            }
        });
        credential_session();
        $issued = credential_issue();
        $plain = $issued['password'];
        $seen_jobs = credential_columns('jobs', ['payload']);
        (new SendOutboxEmail($issued['row']->id))->handle(app(MailService::class));

        $admin = credential_actor(Role::SuperAdmin);
        $this->actingAs($admin);
        $http = $this->get('/staff/email-outbox');

        try {
            $page = Livewire::test(EmailOutboxPage::class)->html();
        } catch (Throwable $exception) {
            $page = $exception->getMessage();
        }

        $health = (string) $this->get('/health')->getContent();
        $outbox = credential_columns('email_outbox', [
            'subject',
            'body_html',
            'body_text',
            'secrets',
            'last_error',
        ]);

        foreach (EmailOutbox::query()->get() as $row) {
            $decoded = json_encode($row->secrets);
            $outbox .= is_string($decoded) ? $decoded : '';
        }

        $log_files = '';
        $directory = storage_path('logs');
        $names = is_dir($directory) ? scandir($directory) : false;

        if (is_array($names)) {
            foreach ($names as $name) {
                $path = $directory.DIRECTORY_SEPARATOR.$name;
                $contents = is_file($path) ? file_get_contents($path) : false;
                $log_files .= is_string($contents) ? $contents : '';
            }
        }

        $haystack = implode("\n", [
            $logged,
            $seen_jobs,
            credential_columns('jobs', ['payload']),
            credential_columns('failed_jobs', ['payload', 'exception']),
            credential_columns('audit_logs', ['action', 'entity', 'before', 'after', 'ip']),
            $outbox,
            credential_columns('cache', ['key', 'value']),
            credential_columns('sessions', ['payload', 'ip_address', 'user_agent']),
            credential_columns('notifications', ['type', 'data']),
            $page,
            (string) $http->getContent(),
            $health,
            $log_files,
        ]);
        $leaked = credential_leaked($haystack, $plain);
        $sent = $issued['row']->fresh()?->status === EmailStatus::Sent;

        expect($sent)->toBeTrue()
            ->and($http->status())->toBe(200)
            ->and($leaked)->toBeFalse();
    } finally {
        DB::table('email_outbox')->delete();
        DB::table('notifications')->delete();
        DB::table('audit_logs')->delete();
        DB::table('sessions')->delete();
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        DB::table('cache')->delete();
        DB::table('cache_locks')->delete();
        DB::table('students')->delete();
        DB::table('counters')->delete();
        DB::table('role_assignments')->delete();
        DB::table('programmes')->delete();
        DB::table('departments')->delete();
        DB::table('faculties')->delete();
        DB::table('academic_sessions')->delete();
        DB::table('users')->delete();

        if ($wrapped && $connection->transactionLevel() === 0) {
            $connection->beginTransaction();
        }
    }
});

it('reports the welcome email as not sent when the log mailer refuses a secret', function () {
    $this->app['env'] = 'production';
    config(['mail.default' => 'log', 'queue.default' => 'sync']);
    credential_session();
    $issued = credential_issue();

    (new SendOutboxEmail($issued['row']->id))->handle(app(MailService::class));
    $issued['row']->refresh();

    expect($issued['state'])->toBe(CredentialDelivery::NOT_SENT)
        ->and($issued['row']->status)->toBe(EmailStatus::Failed)
        ->and($issued['row']->last_error)->toBe(MailError::SECRET_TEMPLATE_REFUSED)
        ->and($issued['row']->secrets)->toBeNull()
        ->and(Student::query()->whereKey($issued['student']->id)->exists())->toBeTrue();
});

it('refuses a faculty admin who tries to resend credentials', function () {
    credential_session();
    $issued = credential_issue();
    $user = $issued['student']->user()->first();
    $faculty_admin = credential_actor(Role::FacultyAdmin);
    $refused = false;

    try {
        if ($user instanceof User) {
            app(CredentialService::class)->resend(
                $faculty_admin,
                $user,
                $issued['student']->matric_no,
                credential_request(),
            );
        }
    } catch (AuthorizationException) {
        $refused = true;
    }

    expect($refused)->toBeTrue();
});

it('records an email change with the old and new address when the account is taken over', function () {
    credential_session();
    $issued = credential_issue();
    $actor = $issued['actor'];
    $issued['student']->load('programme');

    app(StudentService::class)->update(
        $actor,
        (int) $issued['student']->id,
        credential_input($issued['student']->programme, [
            'email' => 'taken.over@example.com',
            'address' => '40 Broad Street',
        ]),
        credential_request(),
    );

    $row = AuditLog::query()->where('action', StudentService::ACTION_UPDATED)->latest('id')->first();
    $before = is_array($row?->before) ? $row->before : [];
    $after = is_array($row?->after) ? $row->after : [];
    $encoded = json_encode($before).json_encode($after);
    $address_hidden = ! credential_leaked($encoded, '40 Broad Street')
        && ! credential_leaked($encoded, '12 Garden Close');

    expect($before['email'] ?? null)->toBe('ada.lovelace@example.com')
        ->and($after['email'] ?? null)->toBe('taken.over@example.com')
        ->and($address_hidden)->toBeTrue();
});
