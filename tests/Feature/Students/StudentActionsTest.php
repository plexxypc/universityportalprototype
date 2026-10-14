<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Filament\Staff\Pages\StudentRecordPage;
use App\Filament\Staff\Pages\StudentsPage;
use App\Models\AuditLog;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use App\Services\CredentialService;
use App\Services\StudentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config(['queue.default' => 'database', 'mail.default' => 'array']);
});

function student_actions_actor(Role $role): User
{
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

function student_actions_request(): Request
{
    return Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.20']);
}

function student_actions_password(): string
{
    return 'Portal-pass-1';
}

it('changes every student status in one transaction and requires a reason', function (): void {
    $registrar = student_actions_actor(Role::Registrar);
    $student = Student::factory()->create();
    $service = app(StudentService::class);
    $request = student_actions_request();

    expect(fn () => $service->changeStatus($registrar, (int) $student->id, StudentStatus::Suspended->value, '   ', $request))
        ->toThrow(ValidationException::class, StudentService::MESSAGE_REASON);

    expect($student->refresh()->status)->toBe(StudentStatus::Active);

    $targets = [
        StudentStatus::Suspended->value => UserStatus::Suspended,
        StudentStatus::Deferred->value => UserStatus::Deactivated,
        StudentStatus::Graduated->value => UserStatus::Deactivated,
        StudentStatus::Withdrawn->value => UserStatus::Deactivated,
        StudentStatus::Active->value => UserStatus::Active,
    ];

    foreach ($targets as $status => $user_status) {
        $service->changeStatus($registrar, (int) $student->id, $status, 'Reason for '.$status, $request);
        $student->refresh();

        expect($student->status->value)->toBe($status)
            ->and($student->user->status)->toBe($user_status);
    }

    expect(AuditLog::query()->where('action', StudentService::ACTION_STATUS_CHANGED)->count())->toBe(5);

    $armed = true;
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $row) use (&$armed): void {
        if ($armed && $row->action === StudentService::ACTION_STATUS_CHANGED) {
            $armed = false;

            throw new RuntimeException('audit insert failed');
        }
    });

    $student->refresh();
    $before_status = $student->status->value;

    expect(fn () => $service->changeStatus($registrar, (int) $student->id, StudentStatus::Suspended->value, 'Should roll back', $request))
        ->toThrow(RuntimeException::class, 'audit insert failed');

    expect($student->refresh()->status->value)->toBe($before_status);
});

it('ends the open session when a student is suspended and restores sign-in on reactivation', function (): void {
    $registrar = student_actions_actor(Role::Registrar);
    $student = Student::factory()->create();
    $account = $student->user;
    $account->password = student_actions_password();
    $account->must_change_password = false;
    $account->save();

    DB::table('sessions')->insert([
        'id' => 'open-student-session',
        'user_id' => $account->id,
        'ip_address' => '203.0.113.21',
        'user_agent' => 'test',
        'payload' => 'payload',
        'last_activity' => time(),
    ]);

    app(StudentService::class)->changeStatus(
        $registrar,
        (int) $student->id,
        StudentStatus::Suspended->value,
        'Unpaid fees',
        student_actions_request(),
    );

    expect(DB::table('sessions')->where('user_id', $account->id)->count())->toBe(0);

    $refused = $this->post('/login', [
        'identifier' => $student->matric_no,
        'password' => student_actions_password(),
    ]);

    expect($refused->headers->get('Location'))->not->toBeNull()
        ->and(str_ends_with((string) $refused->headers->get('Location'), '/student'))->toBeFalse();

    app(StudentService::class)->changeStatus(
        $registrar,
        (int) $student->id,
        StudentStatus::Active->value,
        'Fees cleared',
        student_actions_request(),
    );

    expect(DB::table('sessions')->where('id', 'open-student-session')->count())->toBe(0);

    $allowed = $this->post('/login', [
        'identifier' => $student->matric_no,
        'password' => student_actions_password(),
    ]);

    expect(str_ends_with((string) $allowed->headers->get('Location'), '/student'))->toBeTrue();
});

it('promotes only the eligible students and refuses the commit when that set changes', function (): void {
    $registrar = student_actions_actor(Role::Registrar);
    $ready = Student::factory()->create(['level' => 100]);
    $also_ready = Student::factory()->create(['level' => 200, 'programme_id' => $ready->programme_id]);
    $finished = Student::factory()->create(['level' => 400, 'programme_id' => $ready->programme_id]);
    $graduated = Student::factory()->create(['level' => 100, 'status' => StudentStatus::Graduated]);
    $service = app(StudentService::class);
    $ids = [(int) $ready->id, (int) $also_ready->id, (int) $finished->id, (int) $graduated->id];

    $preview = $service->previewPromotion($registrar, $ids);

    expect(array_column($preview['eligible'], 'id'))->toEqualCanonicalizing([(int) $ready->id, (int) $also_ready->id])
        ->and(array_column($preview['excluded'], 'id'))->toEqualCanonicalizing([(int) $finished->id, (int) $graduated->id]);

    $service->commitPromotion($registrar, $ids, array_column($preview['eligible'], 'id'), student_actions_request());

    expect($ready->refresh()->level)->toBe(200)
        ->and($also_ready->refresh()->level)->toBe(300)
        ->and($finished->refresh()->level)->toBe(400)
        ->and($graduated->refresh()->level)->toBe(100)
        ->and(AuditLog::query()->where('action', StudentService::ACTION_PROMOTED)->count())->toBe(2);

    $again = $service->previewPromotion($registrar, [(int) $ready->id, (int) $also_ready->id]);
    $service->changeStatus($registrar, (int) $also_ready->id, StudentStatus::Suspended->value, 'Hold', student_actions_request());

    expect(fn () => $service->commitPromotion(
        $registrar,
        [(int) $ready->id, (int) $also_ready->id],
        array_column($again['eligible'], 'id'),
        student_actions_request(),
    ))->toThrow(ValidationException::class, StudentService::MESSAGE_PROMOTION_CHANGED);

    expect($ready->refresh()->level)->toBe(200)
        ->and($also_ready->refresh()->level)->toBe(300);
});

it('refuses a promotion list above the cap', function (): void {
    $registrar = student_actions_actor(Role::Registrar);
    $ids = range(1, StudentService::PROMOTION_CAP + 1);

    expect(fn () => app(StudentService::class)->previewPromotion($registrar, $ids))
        ->toThrow(ValidationException::class, StudentService::MESSAGE_PROMOTION_CAP);
});

it('refuses a forged id and a scoped role on status, promotion, resend, and edit', function (): void {
    $own = Student::factory()->create();
    $registrar = student_actions_actor(Role::Registrar);
    $faculty_admin = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $faculty_admin->id,
        'faculty_id' => $own->programme->department->faculty_id,
    ]);
    $service = app(StudentService::class);
    $request = student_actions_request();
    $level = (int) $own->level;

    expect(fn () => $service->changeStatus($registrar, 999999999, StudentStatus::Suspended->value, 'Missing', $request))
        ->toThrow(ValidationException::class, StudentService::MESSAGE_MISSING)
        ->and(fn () => $service->update($registrar, 999999999, ['first_name' => 'Nope'], $request))
        ->toThrow(ValidationException::class, StudentService::MESSAGE_MISSING)
        ->and(fn () => $service->previewPromotion($registrar, [(int) $own->id, 999999999]))
        ->toThrow(ValidationException::class, StudentService::MESSAGE_MISSING);

    expect($own->refresh()->level)->toBe($level)
        ->and($own->status)->toBe(StudentStatus::Active);

    foreach ([
        fn () => $service->changeStatus($faculty_admin, (int) $own->id, StudentStatus::Suspended->value, 'No', $request),
        fn () => $service->update($faculty_admin, (int) $own->id, ['first_name' => 'Nope'], $request),
        fn () => $service->previewPromotion($faculty_admin, [(int) $own->id]),
        fn () => $service->commitPromotion($faculty_admin, [(int) $own->id], [(int) $own->id], $request),
        fn () => app(CredentialService::class)->resend($faculty_admin, $own->user, $own->matric_no, $request, true),
    ] as $call) {
        expect($call)->toThrow(AuthorizationException::class);
    }

    $page = Livewire::actingAs($faculty_admin)->test(StudentRecordPage::class, ['student' => $own->id]);

    $page->assertActionHidden('changeStatus')
        ->assertActionHidden('editStudent')
        ->assertActionHidden('promoteStudent')
        ->assertActionHidden('resendCredentials');

    Livewire::actingAs($faculty_admin)->test(StudentsPage::class)
        ->assertTableBulkActionHidden('promote');

    $officer = User::factory()->create();
    RoleAssignment::factory()->departmentOfficer()->create([
        'user_id' => $officer->id,
        'department_id' => $own->programme->department_id,
    ]);
    $lecturer = student_actions_actor(Role::Lecturer);

    foreach ([$officer, $lecturer] as $scoped) {
        expect(fn () => $service->changeStatus($scoped, (int) $own->id, StudentStatus::Suspended->value, 'No', $request))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => $service->previewPromotion($scoped, [(int) $own->id]))->toThrow(AuthorizationException::class)
            ->and(fn () => app(CredentialService::class)->resend($scoped, $own->user, $own->matric_no, $request, true))
            ->toThrow(AuthorizationException::class);
    }

    expect($own->refresh()->status)->toBe(StudentStatus::Active)
        ->and($own->level)->toBe($level);
});

it('promotes selected students from the list and leaves out those who cannot move', function (): void {
    $registrar = student_actions_actor(Role::Registrar);
    $ready = Student::factory()->create(['level' => 100]);
    $finished = Student::factory()->create([
        'level' => 400,
        'programme_id' => $ready->programme_id,
    ]);

    Livewire::actingAs($registrar)->test(StudentsPage::class)
        ->callTableBulkAction('promote', [$ready, $finished]);

    expect($ready->refresh()->level)->toBe(200)
        ->and($finished->refresh()->level)->toBe(400);
});

it('requires the extra confirmation before replacing a chosen password', function (): void {
    $registrar = student_actions_actor(Role::Registrar);
    $student = Student::factory()->create();
    $account = $student->user;
    $account->must_change_password = false;
    $account->password = student_actions_password();
    $account->save();
    $hash = $account->refresh()->password;

    Livewire::actingAs($registrar)->test(StudentRecordPage::class, ['student' => $student->id])
        ->callAction('resendCredentials')
        ->assertHasActionErrors(['replace_chosen_password']);

    expect($account->refresh()->password)->toBe($hash)
        ->and($account->must_change_password)->toBeFalse();

    Livewire::actingAs($registrar)->test(StudentRecordPage::class, ['student' => $student->id])
        ->callAction('resendCredentials', ['replace_chosen_password' => true])
        ->assertHasNoActionErrors();

    expect($account->refresh()->password)->not->toBe($hash)
        ->and($account->must_change_password)->toBeTrue();
});

it('changes status from the record page', function (): void {
    $registrar = student_actions_actor(Role::Registrar);
    $student = Student::factory()->create();

    Livewire::actingAs($registrar)->test(StudentRecordPage::class, ['student' => $student->id])
        ->callAction('changeStatus', [
            'status' => StudentStatus::Deferred->value,
            'reason' => 'Medical leave',
        ]);

    expect($student->refresh()->status)->toBe(StudentStatus::Deferred)
        ->and($student->user->status)->toBe(UserStatus::Deactivated);
});
