<?php

declare(strict_types=1);

use App\Enums\CourseType;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\InstitutionSetting;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\Student;
use App\Services\SettingsService;

/**
 * An active student, an open semester numbered 1, and one core plus one elective.
 *
 * @return array{student: Student, semester: Semester, session: AcademicSession, programme: Programme, core: Course, elective: Course}
 */
function registration_rules_world(): array
{
    $session = AcademicSession::factory()->current()->create(['name' => '2026/2027']);
    $semester = Semester::factory()->active()->create([
        'session_id' => $session->id,
        'name' => 'First',
        'semester_no' => 1,
        'registration_deadline' => '2026-11-01 00:00:00',
        'add_drop_deadline' => '2026-11-15 00:00:00',
    ]);
    $programme = Programme::factory()->create();
    $student = Student::factory()->create([
        'programme_id' => $programme->id,
        'level' => 100,
        'entry_session_id' => $session->id,
        'status' => StudentStatus::Active,
    ]);
    $core = Course::factory()->create([
        'department_id' => $programme->department_id,
        'code' => 'CSC101',
        'credit_units' => 2,
    ]);
    $elective = Course::factory()->create([
        'department_id' => $programme->department_id,
        'code' => 'CSC102',
        'credit_units' => 2,
    ]);
    ProgrammeCourse::factory()->create([
        'programme_id' => $programme->id,
        'course_id' => $core->id,
        'level' => 100,
        'semester_no' => 1,
        'type' => CourseType::Core,
    ]);
    ProgrammeCourse::factory()->elective()->create([
        'programme_id' => $programme->id,
        'course_id' => $elective->id,
        'level' => 100,
        'semester_no' => 1,
    ]);

    return compact('student', 'semester', 'session', 'programme', 'core', 'elective');
}

/**
 * Store unit limits so a drop test can reach the result check.
 */
function registration_rules_limits(int $min_units, int $max_units): void
{
    $row = InstitutionSetting::query()->first() ?? new InstitutionSetting;
    $row->fill([
        'name' => 'University Portal',
        'code' => 'UNI',
        'matric_pattern' => '{DEPT}/{YEAR}/{SEQ4}',
        'min_units' => $min_units,
        'max_units' => $max_units,
        'approval_required' => true,
        'withhold_results_for_debt' => false,
        'attendance_threshold' => 75,
        'require_minimum_payment' => false,
    ]);
    $row->save();
    app(SettingsService::class)->forgetCache();
}
