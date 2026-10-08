<?php

declare(strict_types=1);

use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores the approved institution setting defaults', function () {
    $settings_id = DB::table('institution_settings')->insertGetId([
        'name' => 'University Portal',
        'code' => 'UNI',
    ]);

    $settings = DB::table('institution_settings')->where('id', $settings_id)->first();

    expect($settings->matric_pattern)->toBe('{DEPT}/{YEAR}/{SEQ4}')
        ->and((int) $settings->withhold_results_for_debt)->toBe(0)
        ->and((int) $settings->require_minimum_payment)->toBe(0)
        ->and((int) $settings->singleton_key)->toBe(1)
        ->and((int) $settings->approval_required)->toBe(1)
        ->and((int) $settings->attendance_threshold)->toBe(75);
});

it('rejects a second institution settings row', function () {
    DB::table('institution_settings')->insert([
        'name' => 'First University',
        'code' => 'UNI',
    ]);

    expect(fn () => DB::table('institution_settings')->insert([
        'name' => 'Second University',
        'code' => 'TWO',
    ]))->toThrow(QueryException::class);
});

it('rejects an institution settings row whose singleton key is not 1', function () {
    expect(fn () => DB::table('institution_settings')->insert([
        'singleton_key' => 2,
        'name' => 'Other University',
        'code' => 'OTH',
    ]))->toThrow(QueryException::class);
});

it('rejects institution settings whose unit limits are invalid', function () {
    expect(fn () => DB::table('institution_settings')->insert([
        'name' => 'University Portal',
        'code' => 'UNI',
        'min_units' => 0,
        'max_units' => 24,
    ]))->toThrow(QueryException::class);

    expect(fn () => DB::table('institution_settings')->insert([
        'name' => 'University Portal',
        'code' => 'UNI',
        'min_units' => 20,
        'max_units' => 10,
    ]))->toThrow(QueryException::class);
});

it('rejects an attendance threshold above 100', function () {
    expect(fn () => DB::table('institution_settings')->insert([
        'name' => 'University Portal',
        'code' => 'UNI',
        'attendance_threshold' => 101,
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate faculty code', function () {
    Faculty::factory()->create(['code' => 'SCI']);

    expect(fn () => Faculty::factory()->create(['code' => 'SCI']))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate department code in another faculty', function () {
    Department::factory()->create(['code' => 'CSC']);

    expect(fn () => Department::factory()->create(['code' => 'CSC']))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate programme code', function () {
    Programme::factory()->create(['code' => 'CSC']);

    expect(fn () => Programme::factory()->create(['code' => 'CSC']))
        ->toThrow(QueryException::class);
});

it('rejects a programme duration outside 1 to 10 years', function () {
    expect(fn () => Programme::factory()->create(['duration_years' => 0]))
        ->toThrow(QueryException::class);

    expect(fn () => Programme::factory()->create(['duration_years' => 11]))
        ->toThrow(QueryException::class);
});

it('rejects a second current academic session', function () {
    DB::table('academic_sessions')->insert([
        'name' => '2025/2026',
        'is_current' => true,
    ]);

    expect(fn () => DB::table('academic_sessions')->insert([
        'name' => '2026/2027',
        'is_current' => true,
    ]))->toThrow(QueryException::class);
});

it('allows another academic session when it is not current', function () {
    DB::table('academic_sessions')->insert([
        ['name' => '2025/2026', 'is_current' => true],
        ['name' => '2026/2027', 'is_current' => false],
    ]);

    expect(DB::table('academic_sessions')->count())->toBe(2);
});

it('generates the current flag from is_current and leaves it out of fillable', function () {
    expect((new AcademicSession)->getFillable())->not->toContain('current_flag');

    $session = AcademicSession::query()->create([
        'name' => '2026/2027',
        'is_current' => true,
        'current_flag' => null,
    ]);
    $session->refresh();

    expect((int) $session->current_flag)->toBe(1);

    $other = AcademicSession::query()->create([
        'name' => '2027/2028',
        'is_current' => false,
        'current_flag' => 1,
    ]);
    $other->refresh();

    expect($other->current_flag)->toBeNull();
});

it('rejects a second active semester', function () {
    $first_session_id = DB::table('academic_sessions')->insertGetId([
        'name' => '2024/2025',
        'is_current' => false,
    ]);
    $second_session_id = DB::table('academic_sessions')->insertGetId([
        'name' => '2025/2026',
        'is_current' => true,
    ]);

    DB::table('semesters')->insert([
        'session_id' => $first_session_id,
        'name' => 'First',
        'is_active' => true,
    ]);

    expect(fn () => DB::table('semesters')->insert([
        'session_id' => $second_session_id,
        'name' => 'First',
        'is_active' => true,
    ]))->toThrow(QueryException::class);
});

it('allows more than one semester when none of the extras are active', function () {
    $session_id = DB::table('academic_sessions')->insertGetId([
        'name' => '2026/2027',
        'is_current' => true,
    ]);

    DB::table('semesters')->insert([
        [
            'session_id' => $session_id,
            'name' => 'First',
            'is_active' => true,
        ],
        [
            'session_id' => $session_id,
            'name' => 'Second',
            'is_active' => false,
        ],
    ]);

    expect(DB::table('semesters')->count())->toBe(2);
});

it('generates the active flag from is_active and leaves it out of fillable', function () {
    expect((new Semester)->getFillable())->not->toContain('active_flag');

    $session = AcademicSession::factory()->create(['is_current' => true]);
    $semester = Semester::query()->create([
        'session_id' => $session->id,
        'name' => 'First',
        'is_active' => true,
        'active_flag' => null,
    ]);
    $semester->refresh();

    expect((int) $semester->active_flag)->toBe(1);
});

it('rejects an add or drop deadline before the registration deadline', function () {
    $session_id = DB::table('academic_sessions')->insertGetId([
        'name' => '2026/2027',
        'is_current' => true,
    ]);

    expect(fn () => DB::table('semesters')->insert([
        'session_id' => $session_id,
        'name' => 'First',
        'is_active' => false,
        'registration_deadline' => '2026-10-01 00:00:00',
        'add_drop_deadline' => '2026-09-01 00:00:00',
    ]))->toThrow(QueryException::class);
});

it('restricts deletes on the deferred faculty and department foreign keys', function () {
    $rows = DB::select(
        'select CONSTRAINT_NAME as constraint_name, DELETE_RULE as delete_rule
         from information_schema.referential_constraints
         where CONSTRAINT_SCHEMA = ? and CONSTRAINT_NAME in (?, ?, ?)',
        [
            DB::getDatabaseName(),
            'role_assignments_faculty_id_foreign',
            'role_assignments_department_id_foreign',
            'staff_department_id_foreign',
        ],
    );

    $delete_rules = collect($rows)->mapWithKeys(
        fn (object $row): array => [$row->constraint_name => $row->delete_rule],
    );

    expect($delete_rules)->toHaveCount(3)
        ->and($delete_rules->get('role_assignments_faculty_id_foreign'))->toBe('RESTRICT')
        ->and($delete_rules->get('role_assignments_department_id_foreign'))->toBe('RESTRICT')
        ->and($delete_rules->get('staff_department_id_foreign'))->toBe('RESTRICT');
});

it('rejects a role assignment for a missing faculty', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => 'FacultyAdmin',
        'faculty_id' => 999999,
        'department_id' => null,
    ]))->toThrow(QueryException::class);
});

it('rejects a role assignment for a missing department', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => 'DepartmentOfficer',
        'faculty_id' => null,
        'department_id' => 999999,
    ]))->toThrow(QueryException::class);
});

it('rejects a staff row for a missing department', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('staff')->insert([
        'user_id' => $user->id,
        'staff_no' => 'STF-9001',
        'title' => 'Dr',
        'department_id' => 999999,
        'status' => 'Active',
    ]))->toThrow(QueryException::class);
});

it('blocks deleting a faculty that a role assignment uses', function () {
    $faculty = Faculty::factory()->create();
    $user = User::factory()->create();

    DB::table('role_assignments')->insert([
        'user_id' => $user->id,
        'role' => 'FacultyAdmin',
        'faculty_id' => $faculty->id,
        'department_id' => null,
    ]);

    expect(fn () => DB::table('faculties')->where('id', $faculty->id)->delete())
        ->toThrow(QueryException::class);
});

it('blocks deleting a department that a staff row uses', function () {
    $department = Department::factory()->create();
    $user = User::factory()->create();

    DB::table('staff')->insert([
        'user_id' => $user->id,
        'staff_no' => 'STF-9002',
        'title' => 'Dr',
        'department_id' => $department->id,
        'status' => 'Active',
    ]);

    expect(fn () => DB::table('departments')->where('id', $department->id)->delete())
        ->toThrow(QueryException::class);
});

it('has the structure tables', function () {
    expect(Schema::hasTable('institution_settings'))->toBeTrue()
        ->and(Schema::hasTable('faculties'))->toBeTrue()
        ->and(Schema::hasTable('departments'))->toBeTrue()
        ->and(Schema::hasTable('programmes'))->toBeTrue()
        ->and(Schema::hasTable('academic_sessions'))->toBeTrue()
        ->and(Schema::hasTable('semesters'))->toBeTrue();
});
