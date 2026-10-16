<?php

declare(strict_types=1);

use App\Enums\ApplicantStatus;
use App\Enums\EmailTemplate;
use App\Enums\Role;
use App\Filament\Staff\Pages\AddApplicantPage;
use App\Filament\Staff\Pages\ApplicantsPage;
use App\Filament\Staff\Pages\EmailOutboxPage;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\EmailOutbox;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\ApplicantService;
use App\Services\CredentialDelivery;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    AcademicSession::factory()->current()->create(['name' => '2025/2026']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function applicant_input(Programme $programme, array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'other_names' => 'Mary',
        'email' => 'ada.applicant@example.test',
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

function applicant_actor(Role $role = Role::Registrar): User
{
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

function applicant_request(): Request
{
    return Request::create('/staff/applicants', 'POST', server: ['REMOTE_ADDR' => '203.0.113.20']);
}

/**
 * @return array{faculty: Faculty, department: Department, programme: Programme}
 */
function applicant_branch(): array
{
    $faculty = Faculty::factory()->create();
    $department = Department::factory()->create(['faculty_id' => $faculty->id]);
    $programme = Programme::factory()->create(['department_id' => $department->id, 'duration_years' => 4]);

    return compact('faculty', 'department', 'programme');
}

it('adds an applicant, normalises the phone, and keeps personal values out of the audit', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $applicant = app(ApplicantService::class)->create(
        $actor,
        applicant_input($branch['programme']),
        applicant_request(),
    );

    $audit = AuditLog::query()->where('action', ApplicantService::ACTION_CREATED)->first();
    $encoded = json_encode($audit?->before).json_encode($audit?->after);

    expect($applicant->phone)->toBe('+2348012345678')
        ->and($applicant->status)->toBe(ApplicantStatus::Applied)
        ->and($applicant->entry_session_id)->toBe(AcademicSession::query()->current()->value('id'))
        ->and($applicant->email)->toBe('ada.applicant@example.test')
        ->and($encoded)->not->toContain('ada.applicant@example.test')
        ->and($encoded)->not->toContain('+2348012345678')
        ->and($encoded)->not->toContain('2004-05-01')
        ->and($audit?->after['changed'] ?? [])->toContain('email')
        ->and($audit?->after['changed'] ?? [])->toContain('phone')
        ->and($audit?->after['changed'] ?? [])->toContain('date_of_birth');
});

it('refuses an applicant email that already exists on users', function () {
    $branch = applicant_branch();
    User::factory()->create(['email' => 'taken@example.test']);

    expect(fn () => app(ApplicantService::class)->create(
        applicant_actor(),
        applicant_input($branch['programme'], ['email' => 'taken@example.test']),
        applicant_request(),
    ))->toThrow(ValidationException::class, ApplicantService::MESSAGE_EMAIL_TAKEN);

    expect(Applicant::query()->count())->toBe(0);
});

it('refuses a repeated status and audits only the id and the statuses', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme']), applicant_request());

    expect(fn () => $service->changeStatus($actor, (int) $applicant->id, ApplicantStatus::Applied->value, '', applicant_request()))
        ->toThrow(ValidationException::class, ApplicantService::MESSAGE_TRANSITION);

    $applicant->refresh();
    $audit = AuditLog::query()->where('action', ApplicantService::ACTION_REFUSED)->first();
    $encoded = json_encode($audit?->before).json_encode($audit?->after);

    expect($applicant->status)->toBe(ApplicantStatus::Applied)
        ->and($audit?->before)->toEqual([
            'applicant_id' => (int) $applicant->id,
            'status' => ApplicantStatus::Applied->value,
        ])
        ->and($audit?->after)->toEqual([
            'applicant_id' => (int) $applicant->id,
            'status' => ApplicantStatus::Applied->value,
        ])
        ->and($encoded)->not->toContain('ada.applicant@example.test');
});

it('queues one admission email on the first move to admitted', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme']), applicant_request());
    $service->changeStatus($actor, (int) $applicant->id, ApplicantStatus::UnderReview->value, '', applicant_request());
    $service->changeStatus($actor, (int) $applicant->id, ApplicantStatus::Admitted->value, '', applicant_request());

    $rows = EmailOutbox::query()->where('template', EmailTemplate::Admission->value)->get();
    $row = $rows->first();
    $body = (string) $row?->body_html.(string) $row?->body_text.(string) $row?->subject;

    expect($rows)->toHaveCount(1)
        ->and($row?->related_type)->toBe(ApplicantService::RELATED_TYPE)
        ->and($row?->related_id)->toBe((int) $applicant->id)
        ->and($row?->user_id)->toBeNull()
        ->and($row?->secrets)->toBeNull()
        ->and($body)->toContain($branch['programme']->name)
        ->and($body)->not->toContain('temporary_password')
        ->and($service->admissionQueued((int) $applicant->id))->toBeTrue();
});

it('stores a rejection reason and refuses a rejection without one', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme']), applicant_request());

    expect(fn () => $service->changeStatus($actor, (int) $applicant->id, ApplicantStatus::Rejected->value, ' ', applicant_request()))
        ->toThrow(ValidationException::class, ApplicantService::MESSAGE_REASON);

    $service->changeStatus($actor, (int) $applicant->id, ApplicantStatus::Rejected->value, 'The documents were incomplete.', applicant_request());
    $audit = AuditLog::query()->where('action', ApplicantService::ACTION_STATUS)->first();

    expect($applicant->refresh()->status)->toBe(ApplicantStatus::Rejected)
        ->and($audit?->after['reason'] ?? null)->toBe('The documents were incomplete.')
        ->and(json_encode($audit?->after))->not->toContain('ada.applicant@example.test');
});

it('returns the same not-found result for a missing applicant id', function () {
    $actor = applicant_actor();

    expect(fn () => app(ApplicantService::class)->changeStatus($actor, 999999, ApplicantStatus::Admitted->value, '', applicant_request()))
        ->toThrow(ValidationException::class, ApplicantService::MESSAGE_MISSING);

    expect(AuditLog::query()->where('action', ApplicantService::ACTION_REFUSED)->count())->toBe(0);
});

it('refuses a faculty admin who tries to change an applicant', function () {
    $branch = applicant_branch();
    $applicant = Applicant::factory()->create(['programme_id' => $branch['programme']->id]);
    $admin = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $admin->id,
        'faculty_id' => $branch['faculty']->id,
    ]);

    expect(fn () => app(ApplicantService::class)->changeStatus($admin, (int) $applicant->id, ApplicantStatus::UnderReview->value, '', applicant_request()))
        ->toThrow(AuthorizationException::class);
});

it('shows a faculty admin only applicants in that faculty', function () {
    $own = applicant_branch();
    $other = applicant_branch();
    $visible = Applicant::factory()->create([
        'programme_id' => $own['programme']->id,
        'first_name' => 'ScopedAda',
    ]);
    $hidden = Applicant::factory()->create([
        'programme_id' => $other['programme']->id,
        'first_name' => 'OtherBola',
    ]);
    $admin = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $admin->id,
        'faculty_id' => $own['faculty']->id,
    ]);

    Livewire::actingAs($admin)->test(ApplicantsPage::class)
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$hidden]);

    $this->actingAs($admin)->get('/staff/applicants/create')->assertForbidden();
});

it('shows a lecturer no applicants and lets a registrar open the add page', function () {
    $branch = applicant_branch();
    Applicant::factory()->create(['programme_id' => $branch['programme']->id]);
    $lecturer = applicant_actor(Role::Lecturer);

    Livewire::actingAs($lecturer)->test(ApplicantsPage::class)
        ->assertCanNotSeeTableRecords(Applicant::query()->get())
        ->assertSee('No applicants yet');

    Livewire::actingAs(applicant_actor())->test(AddApplicantPage::class)->assertOk();
});

it('copies an applicant onto one student and sends admission once from an earlier status', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme']), applicant_request());

    $plain = '';
    EmailOutbox::created(function (EmailOutbox $row) use (&$plain): void {
        if ($row->template !== EmailTemplate::Credentials->value || $plain !== '') {
            return;
        }

        $secrets = $row->secrets;
        $password = is_array($secrets) ? ($secrets['temporary_password'] ?? '') : '';
        $plain = is_string($password) ? $password : '';
    });

    $created = $service->convert($actor, (int) $applicant->id, applicant_request());
    $student = $created->student->fresh(['user']);
    $admission = EmailOutbox::query()->where('template', EmailTemplate::Admission->value)->first();
    $has_secret = $plain !== '';
    $applicant_audit = '';
    $all_audit = '';

    foreach (AuditLog::query()->get(['entity', 'before', 'after']) as $row) {
        $encoded = json_encode($row->before).json_encode($row->after);
        $all_audit .= $encoded;

        if ($row->entity === 'applicants') {
            $applicant_audit .= $encoded;
        }
    }

    $admission_text = (string) $admission?->body_html.(string) $admission?->body_text;
    $leaked = $has_secret && (str_contains($admission_text, $plain) || str_contains($all_audit, $plain));

    expect($student)->not->toBeNull()
        ->and($student?->applicant_id)->toBe($applicant->id)
        ->and($student?->user?->email)->toBe('ada.applicant@example.test')
        ->and($student?->user?->phone)->toBe('+2348012345678')
        ->and($student?->first_name)->toBe('Ada')
        ->and($student?->programme_id)->toBe($branch['programme']->id)
        ->and($student?->level)->toBe(100)
        ->and($applicant->refresh()->status)->toBe(ApplicantStatus::Converted)
        ->and(EmailOutbox::query()->where('template', EmailTemplate::Admission->value)->count())->toBe(1)
        ->and($admission?->related_type)->toBe(ApplicantService::RELATED_TYPE)
        ->and($admission?->related_id)->toBe($applicant->id)
        ->and($admission?->secrets)->toBeNull()
        ->and($admission_text)->toContain($branch['programme']->name)
        ->and($has_secret)->toBeTrue()
        ->and($leaked)->toBeFalse()
        ->and($applicant_audit)->not->toContain('ada.applicant@example.test')
        ->and($applicant_audit)->not->toContain('+2348012345678')
        ->and($applicant_audit)->not->toContain('2004-05-01')
        ->and(User::query()->where('email', 'ada.applicant@example.test')->count())->toBe(1);
});

it('does not send a second admission email when the applicant was already admitted', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme']), applicant_request());
    $service->changeStatus($actor, (int) $applicant->id, ApplicantStatus::Admitted->value, '', applicant_request());

    $service->convert($actor, (int) $applicant->id, applicant_request());

    expect(EmailOutbox::query()->where('template', EmailTemplate::Admission->value)->count())->toBe(1)
        ->and(EmailOutbox::query()->where('template', EmailTemplate::Credentials->value)->count())->toBe(1)
        ->and($applicant->refresh()->status)->toBe(ApplicantStatus::Converted);
});

it('creates one account when convert is repeated and audits the refusal', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme']), applicant_request());
    $service->convert($actor, (int) $applicant->id, applicant_request());

    expect(fn () => $service->convert($actor, (int) $applicant->id, applicant_request()))
        ->toThrow(ValidationException::class, ApplicantService::MESSAGE_CONVERTED);

    $audit = AuditLog::query()->where('action', ApplicantService::ACTION_REFUSED)->latest('id')->first();

    expect(User::query()->where('email', 'ada.applicant@example.test')->count())->toBe(1)
        ->and(Student::query()->count())->toBe(1)
        ->and($audit?->before)->toEqual([
            'applicant_id' => $applicant->id,
            'status' => ApplicantStatus::Converted->value,
        ])
        ->and($audit?->after)->toEqual([
            'applicant_id' => $applicant->id,
            'status' => ApplicantStatus::Converted->value,
        ]);
});

it('leaves an applicant unconverted when the email already belongs to a user', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme']), applicant_request());
    User::factory()->create(['email' => 'ada.applicant@example.test']);

    expect(fn () => $service->convert($actor, (int) $applicant->id, applicant_request()))
        ->toThrow(ValidationException::class, ApplicantService::MESSAGE_EMAIL_TAKEN);

    expect($applicant->refresh()->status)->toBe(ApplicantStatus::Applied)
        ->and(Student::query()->count())->toBe(0)
        ->and(User::query()->where('email', 'ada.applicant@example.test')->count())->toBe(1);
});

it('rolls the account, the outbox row, and the status back when admission fails after the user exists', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $applicant = app(ApplicantService::class)->create($actor, applicant_input($branch['programme']), applicant_request());
    app()->instance('applicant.convert.fail_admission', true);
    $credentials_existed = false;
    EmailOutbox::creating(function (EmailOutbox $row) use (&$credentials_existed): void {
        if (! app()->bound('applicant.convert.fail_admission') || $row->template !== EmailTemplate::Admission->value) {
            return;
        }

        $credentials_existed = EmailOutbox::query()->where('template', EmailTemplate::Credentials->value)->exists();

        throw new RuntimeException('admission failed after the account was created');
    });

    try {
        expect(fn () => app(ApplicantService::class)->convert($actor, (int) $applicant->id, applicant_request()))
            ->toThrow(RuntimeException::class);
    } finally {
        app()->forgetInstance('applicant.convert.fail_admission');
    }

    expect($credentials_existed)->toBeTrue()
        ->and(User::query()->where('email', 'ada.applicant@example.test')->exists())->toBeFalse()
        ->and(Student::query()->count())->toBe(0)
        ->and(EmailOutbox::query()->count())->toBe(0)
        ->and($applicant->refresh()->status)->toBe(ApplicantStatus::Applied);
});

it('refuses bulk conversion before any write when production uses the log mailer', function () {
    $this->app['env'] = 'production';
    config(['mail.default' => 'log', 'queue.default' => 'sync']);
    $branch = applicant_branch();
    $actor = applicant_actor();
    $applicant = app(ApplicantService::class)->create($actor, applicant_input($branch['programme']), applicant_request());
    $users = User::query()->count();

    expect(fn () => app(ApplicantService::class)->convertMany($actor, [(int) $applicant->id], applicant_request()))
        ->toThrow(ValidationException::class, ApplicantService::MESSAGE_LOG_MAILER);

    expect(User::query()->count())->toBe($users)
        ->and(Student::query()->count())->toBe(0)
        ->and(EmailOutbox::query()->count())->toBe(0)
        ->and($applicant->refresh()->status)->toBe(ApplicantStatus::Applied)
        ->and(AuditLog::query()->where('action', ApplicantService::ACTION_CONVERTED)->count())->toBe(0);
});

it('creates the student and reports the welcome email as not sent for one conversion', function () {
    $this->app['env'] = 'production';
    config(['mail.default' => 'log', 'queue.default' => 'sync']);
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme'], [
        'email' => 'single.convert@example.test',
    ]), applicant_request());

    $created = $service->convert($actor, (int) $applicant->id, applicant_request());

    expect($created->delivery->state)->toBe(CredentialDelivery::NOT_SENT)
        ->and($service->createdMessage($created))->toBe(
            'Student '.$created->student->matric_no.' was created. The welcome email was not sent.',
        )
        ->and($applicant->refresh()->status)->toBe(ApplicantStatus::Converted)
        ->and(Student::query()->count())->toBe(1);
});

it('drops a forged id and converts only the eligible applicant', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme']), applicant_request());
    $rejected = Applicant::factory()->rejected()->create([
        'programme_id' => $branch['programme']->id,
        'entry_session_id' => $applicant->entry_session_id,
        'email' => 'rejected.applicant@example.test',
    ]);
    $plan = $service->preview($actor, [(int) $applicant->id, (int) $rejected->id, 999999]);

    expect($plan['eligible'])->toBe([(int) $applicant->id])
        ->and($plan['skipped'])->toBe(2)
        ->and($service->previewMessage($actor, [(int) $applicant->id, (int) $rejected->id, 999999]))
        ->toBe('1 applicant will be admitted and converted. 2 will be skipped.');

    $batch = $service->convertMany($actor, [(int) $applicant->id, (int) $rejected->id, 999999], applicant_request());

    expect($batch->converted)->toBe(1)
        ->and($batch->skipped)->toBe(2)
        ->and($batch->failures)->toBe([])
        ->and($rejected->refresh()->status)->toBe(ApplicantStatus::Rejected)
        ->and(Student::query()->count())->toBe(1);
});

it('refuses a faculty admin who tries to convert an applicant', function () {
    $branch = applicant_branch();
    $applicant = app(ApplicantService::class)->create(
        applicant_actor(),
        applicant_input($branch['programme']),
        applicant_request(),
    );

    expect(fn () => app(ApplicantService::class)->convert(
        applicant_actor(Role::FacultyAdmin),
        (int) $applicant->id,
        applicant_request(),
    ))->toThrow(AuthorizationException::class);

    expect($applicant->refresh()->status)->toBe(ApplicantStatus::Applied)
        ->and(Student::query()->count())->toBe(0);
});

it('shows the not-sent message for one conversion and refuses bulk on the log mailer', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $single = app(ApplicantService::class)->create($actor, applicant_input($branch['programme'], [
        'email' => 'page.single@example.test',
    ]), applicant_request());
    $bulk = app(ApplicantService::class)->create($actor, applicant_input($branch['programme'], [
        'email' => 'page.bulk@example.test',
    ]), applicant_request());
    $this->app['env'] = 'production';
    config(['mail.default' => 'log', 'queue.default' => 'sync']);

    Livewire::actingAs($actor)
        ->test(ApplicantsPage::class)
        ->callTableAction('convert', $single);

    $student = Student::query()->first();

    expect($student)->not->toBeNull()
        ->and($single->refresh()->status)->toBe(ApplicantStatus::Converted);

    Notification::assertNotified('Student '.$student?->matric_no.' was created. The welcome email was not sent.');

    Livewire::actingAs($actor)
        ->test(ApplicantsPage::class)
        ->callTableBulkAction('convert', [$bulk]);

    expect($bulk->refresh()->status)->toBe(ApplicantStatus::Applied)
        ->and(Student::query()->count())->toBe(1)
        ->and(User::query()->where('email', 'page.bulk@example.test')->exists())->toBeFalse();

    Notification::assertNotified(ApplicantService::MESSAGE_LOG_MAILER);
});

/**
 * Each allowed step in the pipeline.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function applicant_allowed_transitions(): array
{
    return [
        'Applied to Under review' => ['Applied', 'Under review'],
        'Applied to Admitted' => ['Applied', 'Admitted'],
        'Applied to Rejected' => ['Applied', 'Rejected'],
        'Under review to Admitted' => ['Under review', 'Admitted'],
        'Under review to Rejected' => ['Under review', 'Rejected'],
        'Admitted to Rejected' => ['Admitted', 'Rejected'],
    ];
}

/**
 * Every other pair, including a repeated status and a typed address.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function applicant_refused_transitions(): array
{
    $allowed = [
        'Applied' => ['Under review', 'Admitted', 'Rejected'],
        'Under review' => ['Admitted', 'Rejected'],
        'Admitted' => ['Rejected'],
        'Rejected' => [],
        'Converted' => [],
    ];
    $cases = [];

    foreach ($allowed as $from => $next) {
        foreach (array_keys($allowed) as $to) {
            if (in_array($to, $next, true)) {
                continue;
            }

            $cases[$from.' to '.$to] = [$from, $to];
        }
    }

    $cases['Applied to a typed address'] = ['Applied', 'ada@example.test'];

    return $cases;
}

it('allows every applicant status transition', function (string $from, string $to): void {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme'], [
        'email' => 'allow.'.md5($from.$to).'@example.test',
    ]), applicant_request());
    $applicant->status = ApplicantStatus::from($from);
    $applicant->save();
    $reason = $to === ApplicantStatus::Rejected->value ? 'The documents were incomplete.' : '';

    $service->changeStatus($actor, (int) $applicant->id, $to, $reason, applicant_request());
    $audit = AuditLog::query()->where('action', ApplicantService::ACTION_STATUS)->latest('id')->first();

    expect($applicant->refresh()->status->value)->toBe($to)
        ->and($audit?->after['status'] ?? null)->toBe($to)
        ->and(EmailOutbox::query()->where('template', EmailTemplate::Admission->value)->count())
        ->toBe($to === ApplicantStatus::Admitted->value ? 1 : 0);

    if ($to === ApplicantStatus::Rejected->value) {
        expect($audit?->after['reason'] ?? null)->toBe('The documents were incomplete.');
    }
})->with(applicant_allowed_transitions());

it('refuses every other status transition and audits only the id and the statuses', function (string $from, string $to): void {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme'], [
        'email' => 'refuse.'.md5($from.$to).'@example.test',
    ]), applicant_request());
    $applicant->status = ApplicantStatus::from($from);
    $applicant->save();

    expect(fn () => $service->changeStatus($actor, (int) $applicant->id, $to, '', applicant_request()))
        ->toThrow(ValidationException::class, ApplicantService::MESSAGE_TRANSITION);

    $attempted = ApplicantStatus::tryFrom($to);
    $audit = AuditLog::query()->where('action', ApplicantService::ACTION_REFUSED)->latest('id')->first();
    $encoded = json_encode($audit?->before).json_encode($audit?->after);

    expect($applicant->refresh()->status->value)->toBe($from)
        ->and($audit?->before)->toEqual([
            'applicant_id' => (int) $applicant->id,
            'status' => $from,
        ])
        ->and($audit?->after)->toEqual([
            'applicant_id' => (int) $applicant->id,
            'status' => $attempted instanceof ApplicantStatus ? $attempted->value : $from,
        ])
        ->and($encoded)->not->toContain('ada@example.test')
        ->and($encoded)->not->toContain('refuse.');
})->with(applicant_refused_transitions());

it('keeps a converted applicant read-only', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $service = app(ApplicantService::class);
    $applicant = $service->create($actor, applicant_input($branch['programme'], [
        'email' => 'readonly.applicant@example.test',
    ]), applicant_request());
    $service->convert($actor, (int) $applicant->id, applicant_request());

    expect(fn () => $service->update($actor, (int) $applicant->id, applicant_input($branch['programme'], [
        'email' => 'readonly.applicant@example.test',
        'last_name' => 'Changed',
    ]), applicant_request()))->toThrow(ValidationException::class, ApplicantService::MESSAGE_CONVERTED);

    expect(fn () => $service->changeStatus($actor, (int) $applicant->id, ApplicantStatus::Rejected->value, 'No longer admitted.', applicant_request()))
        ->toThrow(ValidationException::class, ApplicantService::MESSAGE_TRANSITION);

    expect($applicant->refresh()->status)->toBe(ApplicantStatus::Converted)
        ->and($applicant->last_name)->toBe('Lovelace');

    $this->actingAs($actor)
        ->get('/staff/applicants/create?applicant='.$applicant->id)
        ->assertNotFound();

    Livewire::actingAs($actor)->test(ApplicantsPage::class)
        ->assertTableActionHidden('edit', $applicant)
        ->assertTableActionHidden('changeStatus', $applicant)
        ->assertTableActionHidden('convert', $applicant);
});

it('returns the same not-found page for a missing applicant id and a forged one', function () {
    $actor = applicant_actor();
    $missing = $this->actingAs($actor)->get('/staff/applicants/create?applicant=999999');
    $forged = $this->actingAs($actor)->get('/staff/applicants/create?applicant=999998');

    expect($missing->status())->toBe(404)
        ->and($forged->status())->toBe($missing->status());

    expect(fn () => app(ApplicantService::class)->changeStatus($actor, 999998, ApplicantStatus::Admitted->value, '', applicant_request()))
        ->toThrow(ValidationException::class, ApplicantService::MESSAGE_MISSING);
});

it('does not show outbox relation columns on the page, in json, in health, or in the log', function () {
    $branch = applicant_branch();
    $actor = applicant_actor();
    $logged = '';
    Log::listen(function (MessageLogged $event) use (&$logged): void {
        $encoded = json_encode([$event->message, $event->context]);

        if (is_string($encoded)) {
            $logged .= $encoded;
        }
    });
    $applicant = app(ApplicantService::class)->create($actor, applicant_input($branch['programme'], [
        'email' => 'hidden.relation@example.test',
    ]), applicant_request());
    app(ApplicantService::class)->changeStatus(
        $actor,
        (int) $applicant->id,
        ApplicantStatus::Admitted->value,
        '',
        applicant_request(),
    );
    $row = EmailOutbox::query()->where('template', EmailTemplate::Admission->value)->first();
    $admin = applicant_actor(Role::SuperAdmin);
    $page = Livewire::actingAs($admin)->test(EmailOutboxPage::class)->html();
    $health = $this->get('/health')->getContent();
    $health = is_string($health) ? $health : '';
    $serialised = $row instanceof EmailOutbox ? $row->toJson() : '';

    expect(str_contains($page, 'related_type'))->toBeFalse()
        ->and(str_contains($page, 'related_id'))->toBeFalse()
        ->and(str_contains($serialised, 'related_type'))->toBeFalse()
        ->and(str_contains($serialised, 'related_id'))->toBeFalse()
        ->and(str_contains($health, 'related_type'))->toBeFalse()
        ->and(str_contains($health, 'related_id'))->toBeFalse()
        ->and(str_contains($logged, 'related_type'))->toBeFalse()
        ->and(str_contains($logged, 'related_id'))->toBeFalse()
        ->and($row?->related_type)->toBe(ApplicantService::RELATED_TYPE);
});
