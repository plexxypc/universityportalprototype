<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\Staff\Pages\StudentRecordPage;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

/**
 * @return array{faculty: Faculty, department: Department, programme: Programme, student: Student}
 */
function student_record_branch(string $first_name): array
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

function student_record_user(Role $role): User
{
    $user = User::factory()->create();
    RoleAssignment::factory()->create([
        'user_id' => $user->id,
        'role' => $role,
    ]);

    return $user;
}

it('returns the same 404 for a missing id and an out of scope id', function (): void {
    $own = student_record_branch('RecordIn');
    $other = student_record_branch('RecordOut');
    $admin = User::factory()->create();
    RoleAssignment::factory()->facultyAdmin()->create([
        'user_id' => $admin->id,
        'faculty_id' => $own['faculty']->id,
    ]);

    $missing = $this->actingAs($admin)->get('/staff/students/999999999');
    $outside = $this->actingAs($admin)->get('/staff/students/'.$other['student']->id);
    $inside = $this->actingAs($admin)->get('/staff/students/'.$own['student']->id);

    $missing_body = str_replace('/staff/students/999999999', '/staff/students/ID', (string) $missing->getContent());
    $outside_body = str_replace('/staff/students/'.$other['student']->id, '/staff/students/ID', (string) $outside->getContent());

    expect($missing->status())->toBe(404)
        ->and($outside->status())->toBe(404)
        ->and($missing_body)->toBe($outside_body)
        ->and($outside_body)->not->toContain('RecordOut')
        ->and($outside_body)->not->toContain($other['student']->matric_no)
        ->and($inside->status())->toBe(200)
        ->and($inside->getContent())->toContain('RecordIn');
});

it('does not query modules that are not built', function (): void {
    $branch = student_record_branch('QuietRecord');
    $registrar = student_record_user(Role::Registrar);
    $tables = [
        'course_registration_items',
        'invoice_lines',
        'results',
        'attendance_records',
        'attendance_sessions',
    ];
    $seen = [];

    DB::listen(function (object $query) use (&$seen, $tables): void {
        foreach ($tables as $table) {
            if (preg_match('/\b'.preg_quote($table, '/').'\b/i', $query->sql) === 1) {
                $seen[$table] = $query->sql;
            }
        }
    });

    $this->actingAs($registrar)
        ->get(StudentRecordPage::getUrl(['student' => $branch['student']->id]))
        ->assertOk()
        ->assertSee(StudentRecordPage::MODULE_LATER);

    expect($seen)->toBe([]);
});

it('shows a registrar the fixed activity sentence and does not query audit logs', function (): void {
    $branch = student_record_branch('AdaRecord');
    AuditLog::factory()->create([
        'action' => 'students.created',
        'entity' => 'students',
        'entity_id' => $branch['student']->id,
        'actor_id' => null,
    ]);
    $registrar = student_record_user(Role::Registrar);
    $queried = false;

    DB::listen(function (object $query) use (&$queried): void {
        if (str_contains($query->sql, 'audit_logs')) {
            $queried = true;
        }
    });

    $this->actingAs($registrar)
        ->get(StudentRecordPage::getUrl(['student' => $branch['student']->id]))
        ->assertOk()
        ->assertSee(StudentRecordPage::ACTIVITY_HIDDEN)
        ->assertDontSee('students.created');

    expect($queried)->toBeFalse();
});

it('shows a super admin only this student activity', function (): void {
    $branch = student_record_branch('SuperRecord');
    $other = student_record_branch('OtherRecord');
    $admin = student_record_user(Role::SuperAdmin);
    AuditLog::factory()->create([
        'action' => 'students.created',
        'entity' => 'students',
        'entity_id' => $branch['student']->id,
        'actor_id' => $admin->id,
    ]);
    AuditLog::factory()->create([
        'action' => 'fees.changed',
        'entity' => 'students',
        'entity_id' => $other['student']->id,
        'actor_id' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->get(StudentRecordPage::getUrl(['student' => $branch['student']->id]))
        ->assertOk()
        ->assertSee('students.created')
        ->assertDontSee('fees.changed')
        ->assertDontSee(StudentRecordPage::ACTIVITY_HIDDEN);
});
