<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * An active staff account. Scoped roles get a real faculty or department.
 */
function setup_nav_user(Role $role): User
{
    $user = User::factory()->create();
    $assignment = RoleAssignment::factory();

    if ($role === Role::FacultyAdmin) {
        $assignment = $assignment->facultyAdmin();
    }

    if ($role === Role::DepartmentOfficer) {
        $assignment = $assignment->departmentOfficer();
    }

    $assignment->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

/**
 * Labels inside the staff sidebar.
 *
 * @return list<string>
 */
function setup_nav_labels(string $html): array
{
    preg_match_all('/fi-sidebar-item-label[^>]*>\s*([^<]+)/', $html, $matches);

    return array_values(array_map(trim(...), $matches[1]));
}

it('shows each setup screen only to roles that may use it', function () {
    $screens = [
        'Institution settings' => [Role::SuperAdmin],
        'Academic sessions' => [Role::SuperAdmin, Role::Registrar, Role::Bursar, Role::FacultyAdmin, Role::DepartmentOfficer, Role::Lecturer, Role::ExamOfficer],
        'Semesters' => [Role::SuperAdmin, Role::Registrar, Role::Bursar, Role::FacultyAdmin, Role::DepartmentOfficer, Role::Lecturer, Role::ExamOfficer],
        'Faculties' => [Role::SuperAdmin, Role::Registrar, Role::Bursar, Role::FacultyAdmin, Role::DepartmentOfficer, Role::Lecturer, Role::ExamOfficer],
        'Departments' => [Role::SuperAdmin, Role::Registrar, Role::Bursar, Role::FacultyAdmin, Role::DepartmentOfficer, Role::Lecturer, Role::ExamOfficer],
        'Programmes' => [Role::SuperAdmin, Role::Registrar, Role::Bursar, Role::FacultyAdmin, Role::DepartmentOfficer, Role::Lecturer, Role::ExamOfficer],
        'Courses' => [Role::SuperAdmin, Role::Registrar, Role::Bursar, Role::FacultyAdmin, Role::DepartmentOfficer, Role::Lecturer, Role::ExamOfficer],
        'Grading' => [Role::SuperAdmin, Role::Registrar, Role::ExamOfficer],
        'Fee categories' => [Role::SuperAdmin, Role::Registrar, Role::Bursar],
        'Fee structures' => [Role::SuperAdmin, Role::Registrar, Role::Bursar],
    ];

    $roles = [
        Role::SuperAdmin,
        Role::Registrar,
        Role::ExamOfficer,
        Role::Bursar,
        Role::FacultyAdmin,
        Role::DepartmentOfficer,
        Role::Lecturer,
    ];

    foreach ($roles as $role) {
        $this->refreshApplication();
        $html = (string) $this->actingAs(setup_nav_user($role))->get('/staff')->assertOk()->getContent();
        $labels = setup_nav_labels($html);

        $wrong = [];

        foreach ($screens as $label => $allowed) {
            $visible = in_array($label, $labels, true);
            $should = in_array($role, $allowed, true);

            if ($visible !== $should) {
                $wrong[] = $role->value.' / '.$label.' / '.($visible ? 'shown' : 'hidden');
            }
        }

        expect($wrong)->toBe([]);
    }
});
