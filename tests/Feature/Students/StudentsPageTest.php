<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\StudentsPage;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{faculty: Faculty, department: Department, programme: Programme, student: Student}
 */
function students_page_branch(string $first_name): array
{
    $faculty = Faculty::factory()->create();
    $department = Department::factory()->create(['faculty_id' => $faculty->id]);
    $programme = Programme::factory()->create(['department_id' => $department->id]);
    $student = Student::factory()->create([
        'programme_id' => $programme->id,
        'first_name' => $first_name,
    ]);

    return compact('faculty', 'department', 'programme', 'student');
}

function students_page_user(Role $role): User
{
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

it('offers a scoped user only their own faculty and department filters', function (): void {
    $own = students_page_branch('Inscope');
    $other = students_page_branch('Outscope');
    $extra = Department::factory()->create(['faculty_id' => $own['faculty']->id]);

    $admin = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $admin->id,
        'faculty_id' => $own['faculty']->id,
    ]);

    $page = Livewire::actingAs($admin)->test(StudentsPage::class);

    expect(array_keys($page->instance()->facultyOptions()))->toBe([$own['faculty']->id])
        ->and(array_keys($page->instance()->departmentOptions()))->toEqualCanonicalizing([
            $own['department']->id,
            $extra->id,
        ]);

    $page->filterTable('faculty', ['faculty_id' => $other['faculty']->id])
        ->assertCanSeeTableRecords([$own['student']])
        ->assertCanNotSeeTableRecords([$other['student']]);
});

it('ignores a department filter outside the officer scope', function (): void {
    $own = students_page_branch('DeptIn');
    $other = students_page_branch('DeptOut');

    $officer = User::factory()->create();
    RoleAssignment::factory()->departmentOfficer()->create([
        'user_id' => $officer->id,
        'department_id' => $own['department']->id,
    ]);

    $page = Livewire::actingAs($officer)->test(StudentsPage::class);

    expect(array_keys($page->instance()->departmentOptions()))->toBe([$own['department']->id])
        ->and(array_keys($page->instance()->facultyOptions()))->toBe([$own['faculty']->id]);

    $page->filterTable('department', ['department_id' => $other['department']->id])
        ->assertCanSeeTableRecords([$own['student']])
        ->assertCanNotSeeTableRecords([$other['student']]);
});

it('shows a faculty admin and a department officer only their own students', function (): void {
    $own = students_page_branch('ScopedAda');
    $other = students_page_branch('ScopedBola');

    $admin = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $admin->id,
        'faculty_id' => $own['faculty']->id,
    ]);

    Livewire::actingAs($admin)->test(StudentsPage::class)
        ->assertCanSeeTableRecords([$own['student']])
        ->assertCanNotSeeTableRecords([$other['student']]);

    $officer = User::factory()->create();
    RoleAssignment::factory()->departmentOfficer()->create([
        'user_id' => $officer->id,
        'department_id' => $own['department']->id,
    ]);

    Livewire::actingAs($officer)->test(StudentsPage::class)
        ->assertCanSeeTableRecords([$own['student']])
        ->assertCanNotSeeTableRecords([$other['student']]);
});

it('shows a lecturer an empty student list', function (): void {
    students_page_branch('HiddenFromLecturer');
    $lecturer = students_page_user(Role::Lecturer);

    Livewire::actingAs($lecturer)->test(StudentsPage::class)
        ->assertCanNotSeeTableRecords(Student::query()->get())
        ->assertSee('No students yet');
});

it('escapes percent underscore and backslash in the student search', function (): void {
    $percent = Student::factory()->create(['first_name' => 'Pct%Name']);
    $underscore = Student::factory()->create(['first_name' => 'Under_Name']);
    $slash = Student::factory()->create(['first_name' => 'Slash\\Name']);
    $plain = Student::factory()->create(['first_name' => 'Plain']);
    $registrar = students_page_user(Role::Registrar);

    Livewire::actingAs($registrar)->test(StudentsPage::class)
        ->searchTable('%')
        ->assertCanSeeTableRecords([$percent])
        ->assertCanNotSeeTableRecords([$underscore, $slash, $plain]);

    Livewire::actingAs($registrar)->test(StudentsPage::class)
        ->searchTable('_')
        ->assertCanSeeTableRecords([$underscore])
        ->assertCanNotSeeTableRecords([$percent, $slash, $plain]);

    Livewire::actingAs($registrar)->test(StudentsPage::class)
        ->searchTable('\\')
        ->assertCanSeeTableRecords([$slash])
        ->assertCanNotSeeTableRecords([$percent, $underscore, $plain]);
});

it('does not run a query per row on a page of 25 students', function (): void {
    $registrar = students_page_user(Role::Registrar);
    Student::factory()->create();

    $count = 0;
    DB::listen(function (object $query) use (&$count): void {
        if (preg_match('/from `(users|programmes|departments|faculties)`/i', $query->sql)) {
            $count++;
        }
    });

    $count = 0;
    Livewire::actingAs($registrar)->test(StudentsPage::class);
    $one = $count;

    Student::factory()->count(24)->create();

    $count = 0;
    Livewire::actingAs($registrar)->test(StudentsPage::class);
    $page = $count;

    expect($page - $one)->toBeLessThan(8);
});
