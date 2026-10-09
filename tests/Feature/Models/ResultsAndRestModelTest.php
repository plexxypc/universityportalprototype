<?php

declare(strict_types=1);

use App\Enums\AnnouncementAudience;
use App\Enums\AttendanceStatus;
use App\Enums\EmailStatus;
use App\Enums\ResultStatus;
use App\Models\Announcement;
use App\Models\AssessmentComponent;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\AuditLog;
use App\Models\ClassificationBand;
use App\Models\Counter;
use App\Models\Course;
use App\Models\Department;
use App\Models\EmailOutbox;
use App\Models\ExamTimetable;
use App\Models\Faculty;
use App\Models\GradeBand;
use App\Models\GradingScheme;
use App\Models\Notification;
use App\Models\Programme;
use App\Models\Result;
use App\Models\ResultScore;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a grading scheme and loads its relationships', function () {
    $scheme = GradingScheme::factory()->create();
    $scheme->load(['assessmentComponents', 'gradeBands', 'classificationBands', 'results']);

    expect($scheme->is_active)->toBeFalse()
        ->and($scheme->assessmentComponents)->toBeEmpty()
        ->and($scheme->gradeBands)->toBeEmpty()
        ->and($scheme->classificationBands)->toBeEmpty()
        ->and($scheme->results)->toBeEmpty();
});

it('creates the active grading scheme', function () {
    $active = GradingScheme::factory()->active()->create();
    GradingScheme::factory()->create();

    expect($active->is_active)->toBeTrue()
        ->and(GradingScheme::query()->active()->pluck('id')->all())->toBe([$active->id]);
});

it('creates an assessment component and loads its scheme', function () {
    $component = AssessmentComponent::factory()->create();
    $component->load(['gradingScheme', 'resultScores']);

    expect($component->gradingScheme)->toBeInstanceOf(GradingScheme::class)
        ->and($component->resultScores)->toBeEmpty()
        ->and((float) $component->max_score)->toBeGreaterThan(0);
});

it('creates a grade band and loads its scheme', function () {
    $band = GradeBand::factory()->create();
    $band->load('gradingScheme');

    expect($band->gradingScheme)->toBeInstanceOf(GradingScheme::class)
        ->and((float) $band->min_score)->toBeLessThanOrEqual((float) $band->max_score)
        ->and((float) $band->points)->toBeGreaterThanOrEqual(0);
});

it('creates a classification band and loads its scheme', function () {
    $band = ClassificationBand::factory()->create();
    $band->load('gradingScheme');

    expect($band->gradingScheme)->toBeInstanceOf(GradingScheme::class)
        ->and((float) $band->min_cgpa)->toBeLessThanOrEqual((float) $band->max_cgpa);
});

it('creates a draft result and loads its relationships', function () {
    $result = Result::factory()->create();
    $result->load(['student', 'course', 'semester', 'gradingScheme', 'enteredBy', 'approvedBy', 'scores']);

    expect($result->student)->toBeInstanceOf(Student::class)
        ->and($result->course)->toBeInstanceOf(Course::class)
        ->and($result->semester)->toBeInstanceOf(Semester::class)
        ->and($result->gradingScheme)->toBeInstanceOf(GradingScheme::class)
        ->and($result->enteredBy)->toBeInstanceOf(User::class)
        ->and($result->approvedBy)->toBeNull()
        ->and($result->scores)->toBeEmpty()
        ->and($result->status)->toBe(ResultStatus::Draft)
        ->and($result->total)->toBeNull();
});

it('creates each result factory state', function () {
    $submitted = Result::factory()->submitted()->create();
    $approved = Result::factory()->approved()->create();
    $approved->load('approvedBy');
    $published = Result::factory()->published()->create();
    $published->load(['student.results', 'course.results', 'semester.results', 'approvedBy.approvedResults', 'enteredBy.enteredResults']);

    expect($submitted->status)->toBe(ResultStatus::Submitted)
        ->and($submitted->total)->toBeNull()
        ->and($submitted->published_at)->toBeNull()
        ->and($approved->status)->toBe(ResultStatus::Approved)
        ->and($approved->approvedBy)->toBeInstanceOf(User::class)
        ->and($approved->published_at)->toBeNull()
        ->and($approved->total)->not->toBeNull()
        ->and($published->status)->toBe(ResultStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->student->results)->toHaveCount(1)
        ->and($published->course->results)->toHaveCount(1)
        ->and($published->semester->results)->toHaveCount(1)
        ->and($published->approvedBy->approvedResults)->toHaveCount(1)
        ->and($published->enteredBy->enteredResults)->toHaveCount(1);
});

it('creates a result score and loads its relationships', function () {
    $score = ResultScore::factory()->create();
    $score->load(['result', 'assessmentComponent']);

    expect($score->result)->toBeInstanceOf(Result::class)
        ->and($score->assessmentComponent)->toBeInstanceOf(AssessmentComponent::class)
        ->and((float) $score->score)->toBeGreaterThanOrEqual(0);
});

it('creates an attendance session and loads its relationships', function () {
    $session = AttendanceSession::factory()->create();
    $session->load(['course', 'semester', 'createdBy', 'records']);

    expect($session->course)->toBeInstanceOf(Course::class)
        ->and($session->semester)->toBeInstanceOf(Semester::class)
        ->and($session->createdBy)->toBeInstanceOf(User::class)
        ->and($session->records)->toBeEmpty();
});

it('creates an attendance record and an absence', function () {
    $record = AttendanceRecord::factory()->create();
    $record->load(['attendanceSession', 'student']);
    $absent = AttendanceRecord::factory()->absent()->create();

    expect($record->attendanceSession)->toBeInstanceOf(AttendanceSession::class)
        ->and($record->student)->toBeInstanceOf(Student::class)
        ->and($record->status)->toBe(AttendanceStatus::Present)
        ->and($absent->status)->toBe(AttendanceStatus::Absent);
});

it('creates an exam sitting and a published sitting', function () {
    $sitting = ExamTimetable::factory()->create();
    $sitting->load(['course', 'semester']);
    $published = ExamTimetable::factory()->published()->create();

    expect($sitting->course)->toBeInstanceOf(Course::class)
        ->and($sitting->semester)->toBeInstanceOf(Semester::class)
        ->and($sitting->published_at)->toBeNull()
        ->and($published->published_at)->not->toBeNull();
});

it('creates an announcement and each audience state', function () {
    $announcement = Announcement::factory()->create();
    $announcement->load(['author', 'faculty', 'department', 'programme']);
    $faculty = Announcement::factory()->forFaculty()->create();
    $faculty->load('faculty');
    $department = Announcement::factory()->forDepartment()->create();
    $department->load('department');
    $programme = Announcement::factory()->forProgramme()->create();
    $programme->load('programme');
    $level = Announcement::factory()->forLevel()->create();
    $published = Announcement::factory()->published()->create();

    expect($announcement->author)->toBeInstanceOf(User::class)
        ->and($announcement->audience)->toBe(AnnouncementAudience::All)
        ->and($announcement->faculty)->toBeNull()
        ->and($faculty->faculty)->toBeInstanceOf(Faculty::class)
        ->and($department->department)->toBeInstanceOf(Department::class)
        ->and($programme->programme)->toBeInstanceOf(Programme::class)
        ->and($level->level)->toBe(200)
        ->and($published->published_at)->not->toBeNull();
});

it('creates a notification and a read notification', function () {
    $notification = Notification::factory()->create();
    $notification->load('user');
    $read = Notification::factory()->read()->create();
    $read->load('user');

    expect($notification->user)->toBeInstanceOf(User::class)
        ->and($notification->read_at)->toBeNull()
        ->and($notification->user->notifications()->first()?->is($notification))->toBeTrue()
        ->and($read->read_at)->not->toBeNull()
        ->and($read->user->notifications()->getModel())->toBeInstanceOf(Notification::class);
});

it('creates a queued email, a sent email, and a failed email', function () {
    $queued = EmailOutbox::factory()->create();
    $queued->load('user');
    $sent = EmailOutbox::factory()->sent()->create();
    $failed = EmailOutbox::factory()->failed()->create();

    expect($queued->user)->toBeInstanceOf(User::class)
        ->and($queued->status)->toBe(EmailStatus::Queued)
        ->and($queued->sent_at)->toBeNull()
        ->and($sent->status)->toBe(EmailStatus::Sent)
        ->and($sent->sent_at)->not->toBeNull()
        ->and($failed->status)->toBe(EmailStatus::Failed)
        ->and($failed->sent_at)->toBeNull()
        ->and($failed->attempts)->toBeGreaterThanOrEqual(0);
});

it('creates a counter', function () {
    $counter = Counter::factory()->create();

    expect($counter->value)->toBeGreaterThanOrEqual(0);
});

it('creates an audit log and loads its actor', function () {
    $log = AuditLog::factory()->create();
    $log->load('actor');

    expect($log->actor)->toBeInstanceOf(User::class);
});
