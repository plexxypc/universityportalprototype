<?php

declare(strict_types=1);

use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\ProgrammeCourse;
use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('rejects a duplicate course code', function () {
    Course::factory()->create(['code' => 'CSC101']);

    expect(fn () => Course::factory()->create(['code' => 'CSC101']))
        ->toThrow(QueryException::class);
});

it('rejects a course with no credit units', function () {
    expect(fn () => Course::factory()->create(['credit_units' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate programme and course pair', function () {
    $mapping = ProgrammeCourse::factory()->create();

    expect(fn () => ProgrammeCourse::factory()->create([
        'programme_id' => $mapping->programme_id,
        'course_id' => $mapping->course_id,
        'level' => 200,
        'semester_no' => 2,
        'type' => 'Elective',
    ]))->toThrow(QueryException::class);
});

it('rejects a programme course level outside 100 to 600', function () {
    expect(fn () => ProgrammeCourse::factory()->create(['level' => 150]))
        ->toThrow(QueryException::class);

    expect(fn () => ProgrammeCourse::factory()->create(['level' => 700]))
        ->toThrow(QueryException::class);
});

it('rejects a semester number other than 1 or 2', function () {
    expect(fn () => ProgrammeCourse::factory()->create(['semester_no' => 0]))
        ->toThrow(QueryException::class);

    expect(fn () => ProgrammeCourse::factory()->create(['semester_no' => 3]))
        ->toThrow(QueryException::class);
});

it('rejects a programme course type other than core or elective', function () {
    $programme = ProgrammeCourse::factory()->create()->programme;

    expect(fn () => DB::table('programme_courses')->insert([
        'programme_id' => $programme->id,
        'course_id' => Course::factory()->create()->id,
        'level' => 100,
        'semester_no' => 1,
        'type' => 'Optional',
    ]))->toThrow(QueryException::class);
});

it('rejects a course as its own prerequisite', function () {
    $course = Course::factory()->create();

    expect(fn () => DB::table('course_prerequisites')->insert([
        'course_id' => $course->id,
        'prerequisite_course_id' => $course->id,
    ]))->toThrow(QueryException::class);
});

it('rejects a duplicate prerequisite pair', function () {
    $course = Course::factory()->create();
    $prerequisite = Course::factory()->create();

    DB::table('course_prerequisites')->insert([
        'course_id' => $course->id,
        'prerequisite_course_id' => $prerequisite->id,
    ]);

    expect(fn () => DB::table('course_prerequisites')->insert([
        'course_id' => $course->id,
        'prerequisite_course_id' => $prerequisite->id,
    ]))->toThrow(QueryException::class);
});

it('allows a two-course prerequisite cycle', function () {
    $first = Course::factory()->create();
    $second = Course::factory()->create();

    DB::table('course_prerequisites')->insert([
        [
            'course_id' => $first->id,
            'prerequisite_course_id' => $second->id,
        ],
        [
            'course_id' => $second->id,
            'prerequisite_course_id' => $first->id,
        ],
    ]);

    expect(DB::table('course_prerequisites')->count())->toBe(2);
});

it('rejects a duplicate staff course and semester assignment', function () {
    $assignment = CourseAssignment::factory()->create();

    expect(fn () => CourseAssignment::factory()->create([
        'staff_id' => $assignment->staff_id,
        'course_id' => $assignment->course_id,
        'semester_id' => $assignment->semester_id,
    ]))->toThrow(QueryException::class);
});

it('allows two staff members to be assigned to the same course and semester', function () {
    $assignment = CourseAssignment::factory()->create();
    $other_staff = Staff::factory()->create();

    CourseAssignment::factory()->create([
        'staff_id' => $other_staff->id,
        'course_id' => $assignment->course_id,
        'semester_id' => $assignment->semester_id,
    ]);

    expect(DB::table('course_assignments')->count())->toBe(2);
});

it('has the course tables', function () {
    expect(Schema::hasTable('courses'))->toBeTrue()
        ->and(Schema::hasTable('programme_courses'))->toBeTrue()
        ->and(Schema::hasTable('course_prerequisites'))->toBeTrue()
        ->and(Schema::hasTable('course_assignments'))->toBeTrue();
});
