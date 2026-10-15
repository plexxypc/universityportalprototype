<?php

declare(strict_types=1);

use App\Enums\ApplicantStatus;
use App\Enums\EmailTemplate;
use App\Enums\Role;
use App\Filament\Staff\Pages\AddApplicantPage;
use App\Filament\Staff\Pages\ApplicantsPage;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\EmailOutbox;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\ApplicantService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
