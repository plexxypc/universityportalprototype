<?php

declare(strict_types=1);

use App\Models\RoleAssignment;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Roll back every migration from the users identity columns onward, run a
 * check, then migrate forward.
 *
 * The count comes from the migrations table so later files still roll back
 * through the identity schema. A fixed step of 3 only reached those files
 * when they were the latest three. The assertions are unchanged.
 *
 * MySQL commits the session on DDL, so the test transaction is closed first
 * and opened again afterwards for RefreshDatabase.
 *
 * @param  callable(): void  $callback
 */
function without_identity_migrations(callable $callback): void
{
    $connection = Schema::getConnection();

    while ($connection->transactionLevel() > 0) {
        $connection->commit();
    }

    $steps = (int) DB::table('migrations')
        ->where('migration', '>=', '2026_10_08_160000_add_identity_columns_to_users_table')
        ->count();

    test()->artisan('migrate:rollback', ['--step' => $steps])->assertSuccessful();

    try {
        $callback();
    } finally {
        test()->artisan('migrate')->assertSuccessful();

        if ($connection->transactionLevel() === 0) {
            $connection->beginTransaction();
        }
    }
}

it('keeps the original users columns and adds the identity columns', function () {
    expect(Schema::hasColumns('users', [
        'id',
        'name',
        'email',
        'email_verified_at',
        'password',
        'remember_token',
        'created_at',
        'updated_at',
        'phone',
        'status',
        'must_change_password',
        'temp_password_expires_at',
        'last_login_at',
    ]))->toBeTrue()
        ->and(Schema::hasTable('role_assignments'))->toBeTrue()
        ->and(Schema::hasTable('staff'))->toBeTrue();
});

it('rolls back the identity migrations and keeps the original users columns', function () {
    without_identity_migrations(function (): void {
        expect(Schema::hasTable('staff'))->toBeFalse()
            ->and(Schema::hasTable('role_assignments'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'phone'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'status'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'must_change_password'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'temp_password_expires_at'))->toBeFalse()
            ->and(Schema::hasColumn('users', 'last_login_at'))->toBeFalse()
            ->and(Schema::hasColumns('users', [
                'id',
                'name',
                'email',
                'email_verified_at',
                'password',
                'remember_token',
                'created_at',
                'updated_at',
            ]))->toBeTrue();
    });

    expect(Schema::hasTable('staff'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'status'))->toBeTrue();
});

it('defaults an existing user to Active when the identity columns are added', function () {
    without_identity_migrations(function (): void {
        $user_id = DB::table('users')->insertGetId([
            'name' => 'Existing Person',
            'email' => 'existing-person@example.test',
            'password' => 'not-a-real-password',
        ]);

        try {
            test()->artisan('migrate')->assertSuccessful();

            expect(DB::table('users')->where('id', $user_id)->value('status'))->toBe('Active');
        } finally {
            DB::table('users')->where('id', $user_id)->delete();
        }
    });
});

it('rejects an invalid users status', function () {
    expect(fn () => DB::table('users')->insert([
        'name' => 'Bad Status',
        'email' => 'bad-status@example.test',
        'password' => 'not-a-real-password',
        'status' => 'Banned',
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate staff number', function () {
    Staff::factory()->create(['staff_no' => 'STF-0001']);

    expect(fn () => Staff::factory()->create(['staff_no' => 'STF-0001']))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate role assignment when faculty and department are null', function () {
    $user = User::factory()->create();

    DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => 'SuperAdmin',
        'faculty_id' => null,
        'department_id' => null,
    ]);

    expect(fn () => DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => 'SuperAdmin',
        'faculty_id' => null,
        'department_id' => null,
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate role assignment when the faculty matches and the department is null', function () {
    $user = User::factory()->create();
    $faculty_id = DB::table('faculties')->insertGetId([
        'name' => 'Science',
        'code' => 'SCI',
    ]);

    DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => 'FacultyAdmin',
        'faculty_id' => $faculty_id,
        'department_id' => null,
    ]);

    expect(fn () => DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => 'FacultyAdmin',
        'faculty_id' => $faculty_id,
        'department_id' => null,
    ]))->toThrow(QueryException::class);
});

it('rejects an invalid role assignment role', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => 'Dean',
        'faculty_id' => null,
        'department_id' => null,
    ]))->toThrow(QueryException::class);
});

it('blocks deleting a user who has a staff row', function () {
    $staff = Staff::factory()->create();

    expect(fn () => User::query()->whereKey($staff->user_id)->delete())
        ->toThrow(QueryException::class);
});

it('blocks deleting a user who has a role assignment', function () {
    $assignment = RoleAssignment::factory()->create();

    expect(fn () => User::query()->whereKey($assignment->user_id)->delete())
        ->toThrow(QueryException::class);
});

it('leaves scope columns out of fillable and out of the factory', function () {
    expect((new RoleAssignment)->getFillable())->not->toContain('faculty_scope')
        ->and((new RoleAssignment)->getFillable())->not->toContain('department_scope')
        ->and(RoleAssignment::factory()->raw())->not->toHaveKey('faculty_scope')
        ->and(RoleAssignment::factory()->raw())->not->toHaveKey('department_scope');

    $user = User::factory()->create();
    $assignment = RoleAssignment::query()->create([
        'user_id' => $user->id,
        'role' => 'SuperAdmin',
        'faculty_id' => null,
        'department_id' => null,
        'faculty_scope' => 4,
        'department_scope' => 4,
    ]);
    $assignment->refresh();

    expect((int) $assignment->faculty_scope)->toBe(0)
        ->and((int) $assignment->department_scope)->toBe(0);
});
