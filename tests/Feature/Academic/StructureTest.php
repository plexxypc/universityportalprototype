<?php

declare(strict_types=1);

use App\Enums\Degree;
use App\Enums\Role;
use App\Filament\Staff\Pages\DepartmentsPage;
use App\Filament\Staff\Pages\FacultiesPage;
use App\Filament\Staff\Pages\ProgrammesPage;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Services\StructureService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('accepts only a degree from the closed list', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(StructureService::class);
    $faculty = $service->createFaculty($registrar, 'Science', 'SCI', session_request());
    $department = $service->createDepartment($registrar, (int) $faculty->getKey(), 'Computer Science', 'CSC', session_request());

    foreach (Degree::cases() as $degree) {
        $programme = $service->createProgramme(
            $registrar,
            (int) $department->getKey(),
            $degree->value.' Computer Science',
            'CSC'.$degree->name,
            $degree->value,
            4,
            session_request(),
        );

        expect($programme->degree)->toBe($degree->value);
    }

    expect(fn () => $service->createProgramme(
        $registrar,
        (int) $department->getKey(),
        'Diploma Computer Science',
        'DIPCSC',
        'Diploma',
        2,
        session_request(),
    ))->toThrow(ValidationException::class, StructureService::MESSAGE_DEGREE);

    expect(Programme::query()->where('code', 'DIPCSC')->exists())->toBeFalse();
});

it('keeps a faculty admin inside their faculty', function () {
    $admin = session_user(Role::FacultyAdmin);
    $faculty_id = $admin->facultyIds()[0];
    $other = Faculty::factory()->create(['code' => 'ART']);
    $service = app(StructureService::class);

    expect(fn () => $service->createFaculty($admin, 'Law', 'LAW', session_request()))
        ->toThrow(AuthorizationException::class);

    $department = $service->createDepartment($admin, $faculty_id, 'Computer Science', 'CSC', session_request());

    expect($department->faculty_id)->toBe($faculty_id);

    expect(fn () => $service->createDepartment($admin, (int) $other->getKey(), 'History', 'HIS', session_request()))
        ->toThrow(AuthorizationException::class);

    expect(Department::query()->where('code', 'HIS')->exists())->toBeFalse();

    $before = $department->name;
    expect(fn () => $service->updateDepartment($admin, (int) $department->getKey(), (int) $other->getKey(), 'Moved', 'CSC', session_request()))
        ->toThrow(AuthorizationException::class);

    expect($department->refresh()->name)->toBe($before)
        ->and((int) $department->faculty_id)->toBe($faculty_id);
});

it('keeps a department officer inside their department', function () {
    $officer = session_user(Role::DepartmentOfficer);
    $department_id = $officer->departmentIds()[0];
    $department = Department::query()->findOrFail($department_id);
    $other = Department::factory()->create(['code' => 'HIS']);
    $service = app(StructureService::class);

    $service->updateDepartment($officer, $department_id, (int) $department->faculty_id, 'Own department', $department->code, session_request());

    expect($department->refresh()->name)->toBe('Own department');

    $faculty_id = (int) $department->faculty_id;

    expect(fn () => $service->updateDepartment($officer, $department_id, (int) $other->faculty_id, 'Moved', $department->code, session_request()))
        ->toThrow(AuthorizationException::class);

    expect($department->refresh()->name)->toBe('Own department')
        ->and((int) $department->faculty_id)->toBe($faculty_id);

    expect(fn () => $service->createDepartment($officer, (int) $department->faculty_id, 'New', 'NEW', session_request()))
        ->toThrow(AuthorizationException::class);

    expect(fn () => $service->updateDepartment($officer, (int) $other->getKey(), (int) $other->faculty_id, 'Forged', 'HIS', session_request()))
        ->toThrow(AuthorizationException::class);

    expect($other->refresh()->name)->not->toBe('Forged');

    $programme = $service->createProgramme($officer, $department_id, 'Computer Science', 'CSC', 'BSc', 4, session_request());

    expect($programme->department_id)->toBe($department_id);

    expect(fn () => $service->createProgramme($officer, (int) $other->getKey(), 'History', 'HIST', 'BA', 4, session_request()))
        ->toThrow(AuthorizationException::class);

    expect(Programme::query()->where('code', 'HIST')->exists())->toBeFalse();
});

it('refuses a tampered structure id', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(StructureService::class);
    $faculty = $service->createFaculty($registrar, 'Science', 'SCI', session_request());

    Livewire::actingAs($registrar)
        ->test(FacultiesPage::class)
        ->call('saveFaculty', 999999, 'Forged', 'FRG');

    Notification::assertNotified(StructureService::MESSAGE_FACULTY_MISSING);

    Livewire::actingAs($registrar)
        ->test(FacultiesPage::class)
        ->call('deleteFaculty', 999999);

    Livewire::actingAs($registrar)
        ->test(DepartmentsPage::class)
        ->call('saveDepartment', 999999, (int) $faculty->getKey(), 'Forged', 'FRG');

    Livewire::actingAs($registrar)
        ->test(ProgrammesPage::class)
        ->call('deleteProgramme', 999999);

    expect($faculty->refresh()->name)->toBe('Science')
        ->and(Department::query()->where('code', 'FRG')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', StructureService::FACULTY_UPDATED)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', StructureService::FACULTY_DELETED)->count())->toBe(0);
});

it('refuses a bursar or faculty admin forged faculty save', function () {
    $registrar = session_user(Role::Registrar);
    $admin = session_user(Role::FacultyAdmin);
    $bursar = session_user(Role::Bursar);
    $faculty = app(StructureService::class)->createFaculty($registrar, 'Science', 'SCI', session_request());

    Livewire::actingAs($registrar)
        ->test(FacultiesPage::class)
        ->call('saveFaculty', (int) $faculty->getKey(), 'Science', 'SCI');

    $updated = AuditLog::query()->where('action', StructureService::FACULTY_UPDATED)->first();

    expect($updated?->before)->toMatchArray(['name' => 'Science', 'code' => 'SCI'])
        ->and($updated?->after)->toMatchArray(['name' => 'Science', 'code' => 'SCI']);

    foreach ([$admin, $bursar] as $actor) {
        try {
            Livewire::actingAs($actor)
                ->test(FacultiesPage::class)
                ->call('saveFaculty', (int) $faculty->getKey(), 'Forged', 'SCI');
        } catch (AuthorizationException) {
            // A faculty outside the account, or a view-only role, is refused.
        }

        expect($faculty->refresh()->name)->toBe('Science');
    }
});

it('shows a friendly message and leaves an in-use faculty unchanged', function () {
    $registrar = session_user(Role::Registrar);
    $service = app(StructureService::class);
    $faculty = $service->createFaculty($registrar, 'Science', 'SCI', session_request());
    $service->createDepartment($registrar, (int) $faculty->getKey(), 'Computer Science', 'CSC', session_request());

    Livewire::actingAs($registrar)
        ->test(FacultiesPage::class)
        ->call('deleteFaculty', (int) $faculty->getKey());

    Notification::assertNotified(StructureService::MESSAGE_FACULTY_IN_USE);

    expect(Faculty::query()->whereKey($faculty->id)->exists())->toBeTrue();
});

it('writes a before and after audit row for a faculty save', function () {
    $registrar = session_user(Role::Registrar);
    $faculty = app(StructureService::class)->createFaculty($registrar, 'Science', 'SCI', session_request());
    $created = AuditLog::query()->where('action', StructureService::FACULTY_CREATED)->first();

    expect($created?->before)->toBeNull()
        ->and($created?->after)->toMatchArray(['name' => 'Science', 'code' => 'SCI'])
        ->and($created?->entity_id)->toBe((int) $faculty->getKey());
});
