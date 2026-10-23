<?php

declare(strict_types=1);

use App\Enums\RepeatPolicy;
use App\Models\AcademicStandingThreshold;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\Result;
use App\Models\Semester;
use App\Services\ResultService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ResultFixture;

uses(RefreshDatabase::class);

it('counts a failed course and omits standing from the student statement', function () {
    $fixture = ResultFixture::make();
    $fail = Course::factory()->create([
        'department_id' => $fixture->department->id,
        'code' => 'CSC202',
        'credit_units' => 3,
    ]);
    $registration = CourseRegistration::query()->first();
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $registration?->id,
        'course_id' => $fail->id,
        'credit_units' => 3,
    ]);
    Result::factory()->published()->create([
        'student_id' => $fixture->student->id,
        'course_id' => $fixture->course->id,
        'semester_id' => $fixture->semester->id,
        'grading_scheme_id' => $fixture->scheme->id,
        'scheme_version' => $fixture->scheme->version,
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
        'entered_by' => $fixture->lecturer->id,
    ]);
    Result::factory()->published()->create([
        'student_id' => $fixture->student->id,
        'course_id' => $fail->id,
        'semester_id' => $fixture->semester->id,
        'grading_scheme_id' => $fixture->scheme->id,
        'scheme_version' => $fixture->scheme->version,
        'total' => '30.00',
        'grade' => 'F',
        'points' => '0.00',
        'entered_by' => $fixture->lecturer->id,
    ]);
    AcademicStandingThreshold::factory()->create([
        'scheme_id' => $fixture->scheme->id,
        'warning_cgpa' => '3.00',
        'probation_cgpa' => '1.50',
    ]);
    $service = app(ResultService::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $staff = $service->statement($fixture->examOfficer, $fixture->student);
    $queries = count(DB::getQueryLog());
    $student = $service->statement($fixture->studentUser, $fixture->student);

    expect($staff['cgpa'])->toBe('2.50')
        ->and($staff['classification'])->toBe('Second Class Lower')
        ->and($staff['standing'])->toBe('Warning')
        ->and($staff['units_attempted'])->toBe(6)
        ->and($staff['units_earned'])->toBe(3)
        ->and($staff['semesters'][0]['gpa'])->toBe('2.50')
        ->and($student['standing'])->toBeNull()
        ->and($student['cgpa'])->toBe('2.50')
        ->and($queries)->toBeLessThan(15);

    expect(fn () => $service->statement($fixture->lecturer, $fixture->student))
        ->toThrow(AuthorizationException::class);
});

it('uses the later attempt after the resit cap for the CGPA', function () {
    $fixture = ResultFixture::make();
    $fixture->scheme->repeat_policy = RepeatPolicy::Latest;
    $fixture->scheme->resit_points_cap = '3.00';
    $fixture->scheme->save();
    $later = Semester::factory()->create([
        'session_id' => $fixture->session->id,
        'name' => 'Second',
        'semester_no' => 2,
        'is_active' => false,
    ]);
    Result::factory()->published()->create([
        'student_id' => $fixture->student->id,
        'course_id' => $fixture->course->id,
        'semester_id' => $fixture->semester->id,
        'grading_scheme_id' => $fixture->scheme->id,
        'scheme_version' => $fixture->scheme->version,
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
        'entered_by' => $fixture->lecturer->id,
    ]);
    $second = CourseRegistration::factory()->approved()->create([
        'student_id' => $fixture->student->id,
        'semester_id' => $later->id,
        'total_units' => 3,
    ]);
    CourseRegistrationItem::factory()->create([
        'course_registration_id' => $second->id,
        'course_id' => $fixture->course->id,
        'credit_units' => 3,
    ]);
    Result::factory()->published()->create([
        'student_id' => $fixture->student->id,
        'course_id' => $fixture->course->id,
        'semester_id' => $later->id,
        'grading_scheme_id' => $fixture->scheme->id,
        'scheme_version' => $fixture->scheme->version,
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
        'entered_by' => $fixture->lecturer->id,
    ]);
    $statement = app(ResultService::class)->statement($fixture->examOfficer, $fixture->student);

    expect($statement['cgpa'])->toBe('3.00')
        ->and($statement['units_attempted'])->toBe(3)
        ->and($statement['units_earned'])->toBe(3)
        ->and($statement['semesters'][0]['gpa'])->toBe('5.00')
        ->and($statement['semesters'][1]['gpa'])->toBe('5.00');
});

it('leaves a published result out of the average when the registration item is missing', function () {
    $fixture = ResultFixture::make();
    Result::factory()->published()->create([
        'student_id' => $fixture->student->id,
        'course_id' => $fixture->course->id,
        'semester_id' => $fixture->semester->id,
        'grading_scheme_id' => $fixture->scheme->id,
        'scheme_version' => $fixture->scheme->version,
        'total' => '70.00',
        'grade' => 'A',
        'points' => '5.00',
        'entered_by' => $fixture->lecturer->id,
    ]);
    CourseRegistrationItem::query()->delete();
    $statement = app(ResultService::class)->statement($fixture->registrar, $fixture->student);

    expect($statement['cgpa'])->toBeNull()
        ->and($statement['units_attempted'])->toBe(0)
        ->and($statement['semesters'][0]['gpa'])->toBeNull()
        ->and($statement['semesters'][0]['courses'][0]['units'])->toBeNull();
});
