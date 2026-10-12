<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\CoursesPage;
use App\Models\AuditLog;
use App\Models\Course;
use App\Models\CoursePrerequisite;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Services\CatalogueService;
use App\Services\StructureService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * @return array{0: Faculty, 1: Department, 2: Programme}
 */
function catalogue_branch(StructureService $service, mixed $registrar, string $faculty_code, string $department_code, string $programme_code): array
{
    $faculty = $service->createFaculty($registrar, $faculty_code.' faculty', $faculty_code, session_request());
    $department = $service->createDepartment($registrar, (int) $faculty->getKey(), $department_code.' department', $department_code, session_request());
    $programme = $service->createProgramme($registrar, (int) $department->getKey(), $programme_code.' programme', $programme_code, 'BSc', 4, session_request());

    return [$faculty, $department, $programme];
}

it('rejects a cycle of two or more courses', function () {
    $registrar = session_user(Role::Registrar);
    $structure = app(StructureService::class);
    $catalogue = app(CatalogueService::class);
    [, $department] = catalogue_branch($structure, $registrar, 'SCI', 'CSC', 'CSC');
    $first = $catalogue->createCourse($registrar, (int) $department->getKey(), 'CSC101', 'Introduction', 3, [], [], session_request());
    $second = $catalogue->createCourse($registrar, (int) $department->getKey(), 'CSC201', 'Structures', 3, [], [(int) $first->getKey()], session_request());
    $third = $catalogue->createCourse($registrar, (int) $department->getKey(), 'CSC301', 'Algorithms', 3, [], [(int) $second->getKey()], session_request());

    Livewire::actingAs($registrar)
        ->test(CoursesPage::class)
        ->call('saveCourse', (int) $first->getKey(), (int) $department->getKey(), 'CSC101', 'Introduction', 3, [], [(int) $second->getKey(), (int) $third->getKey()]);

    Notification::assertNotified('CSC201 and CSC101 form a prerequisite cycle.');

    expect(CoursePrerequisite::query()->where('course_id', $first->id)->exists())->toBeFalse()
        ->and($first->refresh()->title)->toBe('Introduction');

    Livewire::actingAs($registrar)
        ->test(CoursesPage::class)
        ->call('saveCourse', (int) $first->getKey(), (int) $department->getKey(), 'CSC101', 'Introduction', 3, [], [(int) $third->getKey()]);

    Notification::assertNotified('CSC301, CSC201, and CSC101 form a prerequisite cycle.');

    expect(CoursePrerequisite::query()->where('course_id', $first->id)->exists())->toBeFalse();
});

it('refuses a course that requires itself', function () {
    $registrar = session_user(Role::Registrar);
    $structure = app(StructureService::class);
    $catalogue = app(CatalogueService::class);
    [, $department] = catalogue_branch($structure, $registrar, 'SCI', 'CSC', 'CSC');
    $course = $catalogue->createCourse($registrar, (int) $department->getKey(), 'CSC101', 'Introduction', 3, [], [], session_request());

    expect(fn () => $catalogue->updateCourse(
        $registrar,
        (int) $course->getKey(),
        (int) $department->getKey(),
        'CSC101',
        'Introduction',
        3,
        [],
        [(int) $course->getKey()],
        session_request(),
    ))->toThrow(ValidationException::class, 'CSC101 cannot require itself.');

    expect(CoursePrerequisite::query()->count())->toBe(0)
        ->and($course->refresh()->title)->toBe('Introduction');
});

it('lets a registrar map a course onto a programme in another faculty', function () {
    $registrar = session_user(Role::Registrar);
    $structure = app(StructureService::class);
    $catalogue = app(CatalogueService::class);
    [, $science] = catalogue_branch($structure, $registrar, 'SCI', 'CSC', 'CSC');
    [, , $history] = catalogue_branch($structure, $registrar, 'ART', 'HIS', 'HIS');
    $course = $catalogue->createCourse($registrar, (int) $science->getKey(), 'GST101', 'Use of English', 2, [], [], session_request());

    $catalogue->updateCourse(
        $registrar,
        (int) $course->getKey(),
        (int) $science->getKey(),
        'GST101',
        'Use of English',
        2,
        [[
            'programme_id' => (int) $history->getKey(),
            'level' => 100,
            'semester_no' => 1,
            'type' => 'Core',
        ]],
        [],
        session_request(),
    );

    expect(ProgrammeCourse::query()->where('course_id', $course->id)->where('programme_id', $history->id)->exists())->toBeTrue();
});

it('refuses a faculty admin cross faculty programme mapping', function () {
    $registrar = session_user(Role::Registrar);
    $admin = session_user(Role::FacultyAdmin);
    $faculty_id = $admin->facultyIds()[0];
    $structure = app(StructureService::class);
    $catalogue = app(CatalogueService::class);
    $own_department = $structure->createDepartment($registrar, $faculty_id, 'Computer Science', 'CSC', session_request());
    $other_department = $structure->createDepartment($registrar, $faculty_id, 'Mathematics', 'MTH', session_request());
    $own_programme = $structure->createProgramme($registrar, (int) $own_department->getKey(), 'Computer Science', 'CSC', 'BSc', 4, session_request());
    $other_programme = $structure->createProgramme($registrar, (int) $other_department->getKey(), 'Mathematics', 'MTH', 'BSc', 4, session_request());
    $course = $catalogue->createCourse($admin, (int) $own_department->getKey(), 'CSC101', 'Introduction', 3, [[
        'programme_id' => (int) $other_programme->getKey(),
        'level' => 100,
        'semester_no' => 1,
        'type' => 'Core',
    ]], [], session_request());

    expect(ProgrammeCourse::query()->where('course_id', $course->id)->where('programme_id', $other_programme->id)->exists())->toBeTrue();

    $outside = Faculty::factory()->create(['code' => 'ART']);
    $outside_department = Department::factory()->create(['faculty_id' => $outside->id, 'code' => 'HIS']);
    $outside_programme = Programme::factory()->create(['department_id' => $outside_department->id, 'code' => 'HIS', 'degree' => 'BA']);

    expect(fn () => $catalogue->updateCourse(
        $admin,
        (int) $course->getKey(),
        (int) $own_department->getKey(),
        'CSC101',
        'Introduction',
        3,
        [[
            'programme_id' => (int) $outside_programme->getKey(),
            'level' => 100,
            'semester_no' => 1,
            'type' => 'Elective',
        ]],
        [],
        session_request(),
    ))->toThrow(AuthorizationException::class);

    expect(ProgrammeCourse::query()->where('course_id', $course->id)->where('programme_id', $own_programme->id)->exists())->toBeFalse()
        ->and(ProgrammeCourse::query()->where('course_id', $course->id)->where('programme_id', $other_programme->id)->exists())->toBeTrue()
        ->and(ProgrammeCourse::query()->where('programme_id', $outside_programme->id)->exists())->toBeFalse();
});

it('refuses a department officer mapping outside their department', function () {
    $registrar = session_user(Role::Registrar);
    $officer = session_user(Role::DepartmentOfficer);
    $department_id = $officer->departmentIds()[0];
    $department = Department::query()->findOrFail($department_id);
    $structure = app(StructureService::class);
    $catalogue = app(CatalogueService::class);
    $own = $structure->createProgramme($registrar, $department_id, 'Computer Science', 'CSC', 'BSc', 4, session_request());
    $other_department = Department::factory()->create(['faculty_id' => $department->faculty_id, 'code' => 'MTH']);
    $other = Programme::factory()->create(['department_id' => $other_department->id, 'code' => 'MTH', 'degree' => 'BSc']);
    $course = $catalogue->createCourse($officer, $department_id, 'CSC101', 'Introduction', 3, [], [], session_request());

    expect(fn () => $catalogue->updateCourse(
        $officer,
        (int) $course->getKey(),
        $department_id,
        'CSC101',
        'Introduction',
        3,
        [[
            'programme_id' => (int) $other->getKey(),
            'level' => 200,
            'semester_no' => 2,
            'type' => 'Elective',
        ]],
        [],
        session_request(),
    ))->toThrow(AuthorizationException::class);

    expect(ProgrammeCourse::query()->where('course_id', $course->id)->exists())->toBeFalse();

    $catalogue->updateCourse(
        $officer,
        (int) $course->getKey(),
        $department_id,
        'CSC101',
        'Introduction',
        3,
        [[
            'programme_id' => (int) $own->getKey(),
            'level' => 100,
            'semester_no' => 1,
            'type' => 'Core',
        ]],
        [],
        session_request(),
    );

    expect(ProgrammeCourse::query()->where('course_id', $course->id)->where('programme_id', $own->id)->exists())->toBeTrue();
});

it('refuses a tampered course id', function () {
    $registrar = session_user(Role::Registrar);
    $structure = app(StructureService::class);
    $catalogue = app(CatalogueService::class);
    [, $department] = catalogue_branch($structure, $registrar, 'SCI', 'CSC', 'CSC');
    $course = $catalogue->createCourse($registrar, (int) $department->getKey(), 'CSC101', 'Introduction', 3, [], [], session_request());

    Livewire::actingAs($registrar)
        ->test(CoursesPage::class)
        ->call('saveCourse', 999999, (int) $department->getKey(), 'CSC999', 'Forged', 3, [], []);

    Notification::assertNotified(CatalogueService::MESSAGE_MISSING);

    Livewire::actingAs($registrar)
        ->test(CoursesPage::class)
        ->call('deleteCourse', 999999);

    expect($course->refresh()->code)->toBe('CSC101')
        ->and(Course::query()->where('code', 'CSC999')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', CatalogueService::UPDATED)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', CatalogueService::DELETED)->count())->toBe(0);
});

it('shows a friendly message and leaves an in-use course unchanged', function () {
    $registrar = session_user(Role::Registrar);
    $structure = app(StructureService::class);
    $catalogue = app(CatalogueService::class);
    [, $department, $programme] = catalogue_branch($structure, $registrar, 'SCI', 'CSC', 'CSC');
    $course = $catalogue->createCourse($registrar, (int) $department->getKey(), 'CSC101', 'Introduction', 3, [[
        'programme_id' => (int) $programme->getKey(),
        'level' => 100,
        'semester_no' => 1,
        'type' => 'Core',
    ]], [], session_request());

    Livewire::actingAs($registrar)
        ->test(CoursesPage::class)
        ->call('deleteCourse', (int) $course->getKey());

    Notification::assertNotified(CatalogueService::MESSAGE_IN_USE);

    expect(Course::query()->whereKey($course->id)->exists())->toBeTrue();
});

it('writes a before and after audit row for a course save', function () {
    $registrar = session_user(Role::Registrar);
    $structure = app(StructureService::class);
    $catalogue = app(CatalogueService::class);
    [, $department, $programme] = catalogue_branch($structure, $registrar, 'SCI', 'CSC', 'CSC');
    $course = $catalogue->createCourse($registrar, (int) $department->getKey(), 'CSC101', 'Introduction', 3, [], [], session_request());
    $catalogue->updateCourse(
        $registrar,
        (int) $course->getKey(),
        (int) $department->getKey(),
        'CSC101',
        'Introduction to Computing',
        3,
        [[
            'programme_id' => (int) $programme->getKey(),
            'level' => 100,
            'semester_no' => 1,
            'type' => 'Core',
        ]],
        [],
        session_request(),
    );

    $created = AuditLog::query()->where('action', CatalogueService::CREATED)->where('entity_id', $course->id)->first();
    $updated = AuditLog::query()->where('action', CatalogueService::UPDATED)->where('entity_id', $course->id)->first();

    expect($created?->before)->toBeNull()
        ->and($created?->after)->toMatchArray(['code' => 'CSC101', 'title' => 'Introduction', 'mappings' => []])
        ->and($updated?->before)->toMatchArray(['title' => 'Introduction'])
        ->and($updated?->after['title'])->toBe('Introduction to Computing')
        ->and($updated?->after['mappings'][0]['programme_id'])->toBe((int) $programme->getKey());
});
