<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Support\Rbac\RoleScopeValidator;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Cases for which roles require a faculty, which require a department, and which must leave both empty.
 *
 * @return array<string, array{0: Role, 1: ?int, 2: ?int, 3: bool}>
 */
function role_scope_cases(): array
{
    $cases = [
        'faculty admin with no faculty' => [Role::FacultyAdmin, null, null, false],
        'faculty admin with a faculty' => [Role::FacultyAdmin, 1, null, true],
        'faculty admin with a faculty and a department' => [Role::FacultyAdmin, 1, 2, false],
        'faculty admin with only a department' => [Role::FacultyAdmin, null, 2, false],
        'department officer with no department' => [Role::DepartmentOfficer, null, null, false],
        'department officer with a department' => [Role::DepartmentOfficer, null, 2, true],
        'department officer with a faculty and a department' => [Role::DepartmentOfficer, 1, 2, false],
        'department officer with only a faculty' => [Role::DepartmentOfficer, 1, null, false],
        'registrar with a faculty' => [Role::Registrar, 1, null, false],
        'registrar with neither scope' => [Role::Registrar, null, null, true],
        'registrar with a department' => [Role::Registrar, null, 2, false],
        'registrar with both scopes' => [Role::Registrar, 1, 2, false],
        'student role with neither scope' => [Role::Student, null, null, false],
        'student role with a faculty' => [Role::Student, 1, null, false],
    ];

    foreach ([Role::SuperAdmin, Role::Bursar, Role::ExamOfficer, Role::Lecturer] as $role) {
        $name = strtolower(str_replace('_', ' ', (string) preg_replace('/(?<!^)[A-Z]/', ' $0', $role->value)));
        $cases[$name.' with neither scope'] = [$role, null, null, true];
        $cases[$name.' with a faculty'] = [$role, 1, null, false];
        $cases[$name.' with a department'] = [$role, null, 2, false];
    }

    return $cases;
}

it('enforces which roles require a faculty, a department, or neither', function (Role $role, ?int $facultyId, ?int $departmentId, bool $accepted) {
    $validator = new RoleScopeValidator;

    expect($validator->accepts($role, $facultyId, $departmentId))->toBe($accepted)
        ->and($validator->errors($role, $facultyId, $departmentId) === [])->toBe($accepted);
})->with(role_scope_cases());
