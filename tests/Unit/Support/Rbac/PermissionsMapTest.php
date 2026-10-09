<?php

declare(strict_types=1);

use App\Enums\PermissionAccess;
use App\Enums\Role;
use App\Support\Rbac\Permissions;
use Tests\Support\ExpectedPermissionMatrix;
use Tests\TestCase;

uses(TestCase::class);

it('matches the permission matrix cell by cell', function () {
    $expected = ExpectedPermissionMatrix::cells();

    expect(Permissions::rows())->toBe(array_keys($expected))
        ->and(Permissions::keys())->toBe(ExpectedPermissionMatrix::keys())
        ->and(Permissions::SUPER_ADMIN_DENIALS)->toBe([
            'course_registration.submit',
            'payments.make',
        ]);

    foreach ($expected as $row => $roles) {
        foreach (Role::cases() as $role) {
            $cell = Permissions::cell($role, $row);
            [$access, $scopes] = $roles[$role->value];

            expect($cell->access)->toBe($access)
                ->and($cell->scopes)->toBe($scopes);
        }
    }
});

it('grants an action key only at manage and a view key at view or manage', function () {
    $mismatches = [];

    foreach (Role::cases() as $role) {
        foreach (ExpectedPermissionMatrix::keys() as $ability) {
            $actual = Permissions::roleGrants($role, $ability);
            $expected = ExpectedPermissionMatrix::grants($role, $ability);

            if ($actual !== $expected) {
                $mismatches[] = $role->value.' '.$ability;
            }
        }
    }

    expect($mismatches)->toBe([]);
});

it('keeps the faculty admin view cell on results approval without granting the ability', function () {
    $cell = Permissions::cell(Role::FacultyAdmin, 'results_approve');

    expect($cell->access)->toBe(PermissionAccess::View)
        ->and($cell->scopes)->toBe([])
        ->and(Permissions::roleGrants(Role::FacultyAdmin, 'results.approve'))->toBeFalse();
});
