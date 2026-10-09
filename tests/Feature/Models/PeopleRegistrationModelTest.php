<?php

declare(strict_types=1);

use App\Enums\ApplicantStatus;
use App\Enums\CourseType;
use App\Enums\DocumentOwner;
use App\Enums\ImportBatchStatus;
use App\Enums\RegistrationStatus;
use App\Enums\StudentStatus;
use App\Models\Applicant;
use App\Models\Course;
use App\Models\CoursePrerequisite;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\Document;
use App\Models\Guardian;
use App\Models\ImportBatch;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('throws when a document is saved for an unregistered owner class', function () {
    $document = Document::factory()->make();

    expect(function () use ($document): void {
        $document->owner()->associate(User::factory()->create());
        $document->save();
    })->toThrow(ClassMorphViolationException::class);
});

it('creates an applicant and loads its relationships', function () {
    $applicant = Applicant::factory()->create();
    $applicant->load(['programme', 'entrySession', 'importBatch', 'student', 'documents']);

    expect($applicant->programme)->toBeInstanceOf(Programme::class)
        ->and($applicant->entrySession)->toBeNull()
        ->and($applicant->importBatch)->toBeNull()
        ->and($applicant->student)->toBeNull()
        ->and($applicant->documents)->toBeEmpty();
});

it('creates each applicant factory state', function () {
    expect(Applicant::factory()->admitted()->create()->status)->toBe(ApplicantStatus::Admitted)
        ->and(Applicant::factory()->rejected()->create()->status)->toBe(ApplicantStatus::Rejected);
});

it('creates a student and loads its relationships', function () {
    $student = Student::factory()->create();
    $student->load([
        'user',
        'applicant',
        'programme',
        'entrySession',
        'importBatch',
        'guardians',
        'courseRegistrations',
        'documents',
    ]);

    expect($student->user)->toBeInstanceOf(User::class)
        ->and($student->applicant)->toBeNull()
        ->and($student->programme)->toBeInstanceOf(Programme::class)
        ->and($student->entrySession)->not->toBeNull()
        ->and($student->importBatch)->toBeNull()
        ->and($student->guardians)->toBeEmpty()
        ->and($student->courseRegistrations)->toBeEmpty()
        ->and($student->documents)->toBeEmpty();
});

it('creates a graduated student', function () {
    expect(Student::factory()->graduated()->create()->status)->toBe(StudentStatus::Graduated);
});

it('creates a guardian and loads its student', function () {
    $guardian = Guardian::factory()->create();
    $guardian->load('student');

    expect($guardian->student)->toBeInstanceOf(Student::class);
});

it('creates a document for a student and loads that owner', function () {
    $document = Document::factory()->create();
    $document->load('owner');
    $owner = $document->owner;
    $owner->load('documents');

    expect($document->owner_type)->toBe(DocumentOwner::Student)
        ->and($owner)->toBeInstanceOf(Student::class)
        ->and($owner->documents)->toHaveCount(1);
});

it('creates a document for an applicant', function () {
    $document = Document::factory()->forApplicant()->create();
    $document->load('owner');
    $owner = $document->owner;
    $owner->load('documents');

    expect($document->owner_type)->toBe(DocumentOwner::Applicant)
        ->and($owner)->toBeInstanceOf(Applicant::class)
        ->and($owner->documents)->toHaveCount(1);
});

it('creates an import batch and loads its user', function () {
    $batch = ImportBatch::factory()->create();
    $batch->load(['user.importBatches', 'applicants', 'students']);

    expect($batch->user)->toBeInstanceOf(User::class)
        ->and($batch->user->importBatches)->toHaveCount(1)
        ->and($batch->applicants)->toBeEmpty()
        ->and($batch->students)->toBeEmpty();
});

it('creates a completed import batch', function () {
    $batch = ImportBatch::factory()->completed()->create();

    expect($batch->status)->toBe(ImportBatchStatus::Completed)
        ->and($batch->processed_rows)->toBeLessThanOrEqual($batch->total_rows);
});

it('creates a course and loads its catalogue relationships', function () {
    $course = Course::factory()->create();
    $course->load(['department', 'programmeCourses', 'prerequisites', 'dependents', 'assignments']);

    expect($course->department)->not->toBeNull()
        ->and($course->programmeCourses)->toBeEmpty()
        ->and($course->prerequisites)->toBeEmpty()
        ->and($course->dependents)->toBeEmpty()
        ->and($course->assignments)->toBeEmpty()
        ->and($course->credit_units)->toBeGreaterThanOrEqual(1);
});

it('creates a programme course and an elective mapping', function () {
    $mapping = ProgrammeCourse::factory()->create();
    $mapping->load(['programme', 'course']);
    $elective = ProgrammeCourse::factory()->elective()->create();

    expect($mapping->programme)->toBeInstanceOf(Programme::class)
        ->and($mapping->course)->toBeInstanceOf(Course::class)
        ->and($mapping->type)->toBe(CourseType::Core)
        ->and($elective->type)->toBe(CourseType::Elective);
});

it('creates a prerequisite and loads both course directions', function () {
    $link = CoursePrerequisite::factory()->create();
    $link->load(['course.prerequisites', 'prerequisite.dependents']);

    expect($link->course->prerequisites)->toHaveCount(1)
        ->and($link->prerequisite->dependents)->toHaveCount(1)
        ->and($link->course->is($link->prerequisite))->toBeFalse();
});

it('creates a course registration and loads its relationships', function () {
    $registration = CourseRegistration::factory()->create();
    $registration->load(['student', 'semester', 'decidedBy', 'items']);

    expect($registration->student)->toBeInstanceOf(Student::class)
        ->and($registration->semester)->not->toBeNull()
        ->and($registration->decidedBy)->toBeNull()
        ->and($registration->items)->toBeEmpty()
        ->and($registration->status)->toBe(RegistrationStatus::Draft);
});

it('creates each course registration factory state', function () {
    $submitted = CourseRegistration::factory()->submitted()->create();
    $approved = CourseRegistration::factory()->approved()->create();
    $approved->load('decidedBy');
    $rejected = CourseRegistration::factory()->rejected()->create();

    expect($submitted->status)->toBe(RegistrationStatus::Submitted)
        ->and($submitted->submitted_at)->not->toBeNull()
        ->and($approved->status)->toBe(RegistrationStatus::Approved)
        ->and($approved->decidedBy)->toBeInstanceOf(User::class)
        ->and($rejected->status)->toBe(RegistrationStatus::Rejected)
        ->and($rejected->rejection_reason)->not->toBeEmpty();
});

it('creates a course registration item and loads its relationships', function () {
    $item = CourseRegistrationItem::factory()->create();
    $item->load(['courseRegistration', 'course']);

    expect($item->courseRegistration)->toBeInstanceOf(CourseRegistration::class)
        ->and($item->course)->toBeInstanceOf(Course::class)
        ->and($item->credit_units)->toBeGreaterThanOrEqual(1);
});
