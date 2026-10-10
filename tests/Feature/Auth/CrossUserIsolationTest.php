<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AttendanceRecord;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\Department;
use App\Models\Document;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\Receipt;
use App\Models\Result;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\IsolationWorld;

uses(RefreshDatabase::class);

/**
 * Ids visible to this account. The scope is the list control.
 *
 * @param  class-string<Student|CourseRegistration|Invoice|Payment|Receipt|Result|AttendanceRecord|Document|Notification|Faculty|Department|Programme|Course>  $model
 * @return list<int>
 */
function isolation_visible_ids(string $model, User $user): array
{
    return $model::query()
        ->visibleTo($user)
        ->pluck('id')
        ->map(fn (mixed $id): int => (int) $id)
        ->all();
}

it('hides student B from student A for every student-owned model', function () {
    $world = IsolationWorld::make();
    $pairs = [
        'student' => [$world->studentA, $world->studentB],
        'course registration' => [$world->registrationA, $world->registrationB],
        'invoice' => [$world->invoiceA, $world->invoiceB],
        'payment' => [$world->paymentA, $world->paymentB],
        'receipt' => [$world->receiptA, $world->receiptB],
        'published result' => [$world->publishedResult, $world->otherPublished],
        'attendance record' => [$world->attendanceA, $world->attendanceB],
        'document' => [$world->documentA, $world->documentB],
        'notification' => [$world->notificationA, $world->notificationB],
    ];

    expect(Invoice::query()->whereKey($world->invoiceB->id)->exists())->toBeTrue();

    foreach ($pairs as $label => [$own, $other]) {
        $ids = isolation_visible_ids($own::class, $world->userA);

        expect(Gate::forUser($world->userA)->allows('view', $own))->toBeTrue("{$label} own view")
            ->and(Gate::forUser($world->userA)->allows('download', $own))->toBeTrue("{$label} own download")
            ->and(Gate::forUser($world->userA)->allows('view', $other))->toBeFalse("{$label} other view")
            ->and(Gate::forUser($world->userA)->allows('download', $other))->toBeFalse("{$label} other download")
            ->and($ids)->toContain($own->id)
            ->and($ids)->not->toContain($other->id);
    }

    expect($world->studentA->invoices()->pluck('id')->all())->not->toContain($world->invoiceB->id)
        ->and($world->studentA->payments()->pluck('id')->all())->not->toContain($world->paymentB->id)
        ->and($world->studentA->results()->pluck('id')->all())->not->toContain($world->otherPublished->id)
        ->and($world->studentA->courseRegistrations()->pluck('id')->all())->not->toContain($world->registrationB->id)
        ->and($world->studentA->attendanceRecords()->pluck('id')->all())->not->toContain($world->attendanceB->id)
        ->and($world->studentA->documents()->pluck('id')->all())->not->toContain($world->documentB->id)
        ->and($world->userA->notifications()->pluck('id')->all())->not->toContain($world->notificationB->id);
});

it('shows a student only their own published results', function () {
    $world = IsolationWorld::make();
    $ids = isolation_visible_ids(Result::class, $world->userA);

    expect(Gate::forUser($world->userA)->allows('view', $world->publishedResult))->toBeTrue()
        ->and(Gate::forUser($world->userA)->allows('view', $world->draftResult))->toBeFalse()
        ->and(Gate::forUser($world->userA)->allows('view', $world->submittedResult))->toBeFalse()
        ->and(Gate::forUser($world->userA)->allows('view', $world->approvedResult))->toBeFalse()
        ->and(Gate::forUser($world->userA)->allows('view', $world->otherPublished))->toBeFalse()
        ->and($ids)->toContain($world->publishedResult->id)
        ->and($ids)->not->toContain($world->draftResult->id)
        ->and($ids)->not->toContain($world->submittedResult->id)
        ->and($ids)->not->toContain($world->approvedResult->id)
        ->and($ids)->not->toContain($world->otherPublished->id);
});

it('shows draft results only to the assigned lecturer and the super admin', function () {
    $world = IsolationWorld::make();
    $lecturerIds = isolation_visible_ids(Result::class, $world->lecturer);
    $facultyIds = isolation_visible_ids(Result::class, $world->facultyAdmin);
    $departmentIds = isolation_visible_ids(Result::class, $world->departmentOfficer);
    $registrarIds = isolation_visible_ids(Result::class, $world->registrar);
    $examIds = isolation_visible_ids(Result::class, $world->examOfficer);
    $superIds = isolation_visible_ids(Result::class, $world->superAdmin);

    expect(Gate::forUser($world->lecturer)->allows('view', $world->draftResult))->toBeTrue()
        ->and(Gate::forUser($world->lecturer)->allows('enter', $world->draftResult))->toBeTrue()
        ->and($lecturerIds)->toContain($world->draftResult->id)
        ->and($lecturerIds)->toContain($world->otherSemesterDraft->id)
        ->and(Gate::forUser($world->lecturer)->allows('view', $world->otherDraft))->toBeFalse()
        ->and(Gate::forUser($world->lecturer)->allows('enter', $world->otherDraft))->toBeFalse()
        ->and($lecturerIds)->not->toContain($world->otherDraft->id)
        ->and($lecturerIds)->not->toContain($world->submittedResult->id)
        ->and(Gate::forUser($world->superAdmin)->allows('view', $world->draftResult))->toBeTrue()
        ->and(Gate::forUser($world->superAdmin)->allows('view', $world->otherDraft))->toBeTrue()
        ->and($superIds)->toContain($world->draftResult->id)
        ->and($superIds)->toContain($world->otherDraft->id);

    foreach ([
        [$world->facultyAdmin, $facultyIds],
        [$world->departmentOfficer, $departmentIds],
        [$world->registrar, $registrarIds],
        [$world->examOfficer, $examIds],
    ] as [$user, $ids]) {
        expect(Gate::forUser($user)->allows('view', $world->draftResult))->toBeFalse()
            ->and($ids)->not->toContain($world->draftResult->id)
            ->and($ids)->not->toContain($world->otherSemesterDraft->id)
            ->and(Gate::forUser($user)->allows('view', $world->submittedResult))->toBeTrue()
            ->and(Gate::forUser($user)->allows('view', $world->approvedResult))->toBeTrue()
            ->and(Gate::forUser($user)->allows('view', $world->publishedResult))->toBeTrue()
            ->and($ids)->toContain($world->submittedResult->id)
            ->and($ids)->toContain($world->approvedResult->id)
            ->and($ids)->toContain($world->publishedResult->id);
    }

    expect(Gate::forUser($world->facultyAdmin)->allows('view', $world->otherSubmitted))->toBeFalse()
        ->and($facultyIds)->not->toContain($world->otherSubmitted->id)
        ->and(Gate::forUser($world->departmentOfficer)->allows('view', $world->otherSubmitted))->toBeFalse()
        ->and($departmentIds)->not->toContain($world->otherSubmitted->id)
        ->and(Gate::forUser($world->registrar)->allows('view', $world->otherSubmitted))->toBeTrue()
        ->and(Gate::forUser($world->examOfficer)->allows('view', $world->otherSubmitted))->toBeTrue()
        ->and(Gate::forUser($world->departmentOfficer)->allows('approve', $world->submittedResult))->toBeTrue()
        ->and(Gate::forUser($world->departmentOfficer)->allows('approve', $world->draftResult))->toBeFalse()
        ->and(Gate::forUser($world->departmentOfficer)->allows('approve', $world->otherSubmitted))->toBeFalse()
        ->and(Gate::forUser($world->facultyAdmin)->allows('approve', $world->submittedResult))->toBeFalse()
        ->and(Gate::forUser($world->examOfficer)->allows('publish', $world->submittedResult))->toBeTrue()
        ->and(Gate::forUser($world->examOfficer)->allows('publish', $world->draftResult))->toBeFalse()
        ->and(Gate::forUser($world->facultyAdmin)->allows('publish', $world->submittedResult))->toBeFalse()
        ->and(Gate::forUser($world->lecturer)->allows('publish', $world->draftResult))->toBeFalse();
});

it('shows a lecturer only students with an approved registration on an assigned course and semester', function () {
    $world = IsolationWorld::make();
    $ids = isolation_visible_ids(Student::class, $world->lecturer);

    expect(Gate::forUser($world->lecturer)->allows('view', $world->studentA))->toBeTrue()
        ->and($ids)->toContain($world->studentA->id)
        ->and(Gate::forUser($world->lecturer)->allows('view', $world->draftStudent))->toBeFalse()
        ->and(Gate::forUser($world->lecturer)->allows('view', $world->submittedStudent))->toBeFalse()
        ->and(Gate::forUser($world->lecturer)->allows('view', $world->rejectedStudent))->toBeFalse()
        ->and(Gate::forUser($world->lecturer)->allows('view', $world->otherSemesterStudent))->toBeFalse()
        ->and(Gate::forUser($world->lecturer)->allows('view', $world->studentB))->toBeFalse()
        ->and($ids)->not->toContain($world->draftStudent->id)
        ->and($ids)->not->toContain($world->submittedStudent->id)
        ->and($ids)->not->toContain($world->rejectedStudent->id)
        ->and($ids)->not->toContain($world->otherSemesterStudent->id)
        ->and($ids)->not->toContain($world->studentB->id)
        ->and(Gate::forUser($world->lecturer)->allows('viewAny', CourseRegistration::class))->toBeFalse()
        ->and(Gate::forUser($world->lecturer)->allows('view', $world->registrationA))->toBeFalse()
        ->and(isolation_visible_ids(CourseRegistration::class, $world->lecturer))->toBe([]);
});

it('limits a faculty admin to their faculty', function () {
    $world = IsolationWorld::make();

    expect(isolation_visible_ids(Student::class, $world->facultyAdmin))->toContain($world->studentA->id)
        ->and(isolation_visible_ids(Student::class, $world->facultyAdmin))->not->toContain($world->studentB->id)
        ->and(isolation_visible_ids(Faculty::class, $world->facultyAdmin))->toContain($world->facultyA->id)
        ->and(isolation_visible_ids(Faculty::class, $world->facultyAdmin))->not->toContain($world->facultyB->id)
        ->and(isolation_visible_ids(Department::class, $world->facultyAdmin))->toContain($world->departmentA->id)
        ->and(isolation_visible_ids(Department::class, $world->facultyAdmin))->not->toContain($world->departmentB->id)
        ->and(isolation_visible_ids(Programme::class, $world->facultyAdmin))->toContain($world->programmeA->id)
        ->and(isolation_visible_ids(Programme::class, $world->facultyAdmin))->not->toContain($world->programmeB->id)
        ->and(isolation_visible_ids(Course::class, $world->facultyAdmin))->toContain($world->courseAssigned->id)
        ->and(isolation_visible_ids(Course::class, $world->facultyAdmin))->not->toContain($world->courseOther->id)
        ->and(isolation_visible_ids(CourseRegistration::class, $world->facultyAdmin))->toContain($world->registrationA->id)
        ->and(isolation_visible_ids(CourseRegistration::class, $world->facultyAdmin))->not->toContain($world->registrationB->id)
        ->and(isolation_visible_ids(Invoice::class, $world->facultyAdmin))->toBe([])
        ->and(Gate::forUser($world->facultyAdmin)->allows('view', $world->studentB))->toBeFalse()
        ->and(Gate::forUser($world->facultyAdmin)->allows('view', $world->invoiceA))->toBeFalse();
});

it('limits a department officer to their department', function () {
    $world = IsolationWorld::make();

    expect(isolation_visible_ids(Student::class, $world->departmentOfficer))->toContain($world->studentA->id)
        ->and(isolation_visible_ids(Student::class, $world->departmentOfficer))->not->toContain($world->studentB->id)
        ->and(isolation_visible_ids(Department::class, $world->departmentOfficer))->toContain($world->departmentA->id)
        ->and(isolation_visible_ids(Department::class, $world->departmentOfficer))->not->toContain($world->departmentB->id)
        ->and(isolation_visible_ids(Faculty::class, $world->departmentOfficer))->toContain($world->facultyA->id)
        ->and(isolation_visible_ids(Faculty::class, $world->departmentOfficer))->not->toContain($world->facultyB->id)
        ->and(isolation_visible_ids(Programme::class, $world->departmentOfficer))->toContain($world->programmeA->id)
        ->and(isolation_visible_ids(Programme::class, $world->departmentOfficer))->not->toContain($world->programmeB->id)
        ->and(isolation_visible_ids(Course::class, $world->departmentOfficer))->toContain($world->courseAssigned->id)
        ->and(isolation_visible_ids(Course::class, $world->departmentOfficer))->not->toContain($world->courseOther->id)
        ->and(isolation_visible_ids(AttendanceRecord::class, $world->departmentOfficer))->toContain($world->attendanceA->id)
        ->and(isolation_visible_ids(AttendanceRecord::class, $world->departmentOfficer))->not->toContain($world->attendanceB->id)
        ->and(Gate::forUser($world->departmentOfficer)->allows('view', $world->studentB))->toBeFalse();
});

it('limits a lecturer to assigned courses and the structure that owns them', function () {
    $world = IsolationWorld::make();
    $courses = isolation_visible_ids(Course::class, $world->lecturer);

    expect($courses)->toContain($world->courseAssigned->id)
        ->and($courses)->not->toContain($world->courseSubmitted->id)
        ->and($courses)->not->toContain($world->courseOther->id)
        ->and(isolation_visible_ids(Department::class, $world->lecturer))->toContain($world->departmentA->id)
        ->and(isolation_visible_ids(Department::class, $world->lecturer))->not->toContain($world->departmentB->id)
        ->and(isolation_visible_ids(Faculty::class, $world->lecturer))->toContain($world->facultyA->id)
        ->and(isolation_visible_ids(Faculty::class, $world->lecturer))->not->toContain($world->facultyB->id)
        ->and(isolation_visible_ids(Programme::class, $world->lecturer))->toContain($world->programmeA->id)
        ->and(isolation_visible_ids(Programme::class, $world->lecturer))->toContain($world->programmeExtra->id)
        ->and(isolation_visible_ids(Programme::class, $world->lecturer))->not->toContain($world->programmeB->id)
        ->and(isolation_visible_ids(AttendanceRecord::class, $world->lecturer))->toContain($world->attendanceA->id)
        ->and(isolation_visible_ids(AttendanceRecord::class, $world->lecturer))->not->toContain($world->attendanceOtherSemester->id)
        ->and(isolation_visible_ids(AttendanceRecord::class, $world->lecturer))->not->toContain($world->attendanceB->id)
        ->and(Gate::forUser($world->lecturer)->allows('view', $world->courseOther))->toBeFalse()
        ->and(Gate::forUser($world->userA)->allows('viewAny', Course::class))->toBeFalse()
        ->and(isolation_visible_ids(Course::class, $world->userA))->toBe([])
        ->and(isolation_visible_ids(Faculty::class, $world->userA))->toBe([]);
});

it('shows documents only to the owning student, the registrar, and the super admin', function () {
    $world = IsolationWorld::make();

    expect(Gate::forUser($world->userA)->allows('view', $world->documentA))->toBeTrue()
        ->and(Gate::forUser($world->userA)->allows('download', $world->documentA))->toBeTrue()
        ->and(Gate::forUser($world->userA)->allows('view', $world->documentB))->toBeFalse()
        ->and(Gate::forUser($world->userA)->allows('download', $world->documentB))->toBeFalse()
        ->and(Gate::forUser($world->userA)->allows('view', $world->applicantDocument))->toBeFalse()
        ->and(isolation_visible_ids(Document::class, $world->userA))->toContain($world->documentA->id)
        ->and(isolation_visible_ids(Document::class, $world->userA))->not->toContain($world->documentB->id)
        ->and(isolation_visible_ids(Document::class, $world->userA))->not->toContain($world->applicantDocument->id)
        ->and(Gate::forUser($world->registrar)->allows('view', $world->documentA))->toBeTrue()
        ->and(Gate::forUser($world->registrar)->allows('download', $world->documentB))->toBeTrue()
        ->and(Gate::forUser($world->registrar)->allows('view', $world->applicantDocument))->toBeTrue()
        ->and(Gate::forUser($world->registrar)->allows('download', $world->applicantDocument))->toBeTrue()
        ->and(isolation_visible_ids(Document::class, $world->registrar))->toContain($world->applicantDocument->id)
        ->and(Gate::forUser($world->superAdmin)->allows('view', $world->applicantDocument))->toBeTrue()
        ->and(isolation_visible_ids(Document::class, $world->superAdmin))->toContain($world->applicantDocument->id);

    foreach ([
        $world->bursar,
        $world->examOfficer,
        $world->facultyAdmin,
        $world->departmentOfficer,
        $world->lecturer,
    ] as $user) {
        expect(Gate::forUser($user)->allows('viewAny', Document::class))->toBeFalse()
            ->and(Gate::forUser($user)->allows('view', $world->documentA))->toBeFalse()
            ->and(Gate::forUser($user)->allows('download', $world->documentA))->toBeFalse()
            ->and(Gate::forUser($user)->allows('view', $world->applicantDocument))->toBeFalse()
            ->and(isolation_visible_ids(Document::class, $user))->toBe([]);
    }
});

it('lets a super admin see every fixture row and denies the explicit abilities', function () {
    $world = IsolationWorld::make();

    expect(Gate::forUser($world->superAdmin)->allows('course_registration.submit'))->toBeFalse()
        ->and(Gate::forUser($world->superAdmin)->allows('payments.make'))->toBeFalse()
        ->and(Gate::forUser($world->superAdmin)->allows('view', $world->studentB))->toBeTrue()
        ->and(Gate::forUser($world->superAdmin)->allows('view', $world->invoiceB))->toBeTrue()
        ->and(Gate::forUser($world->superAdmin)->allows('download', $world->receiptB))->toBeTrue()
        ->and(Gate::forUser($world->superAdmin)->allows('view', $world->draftResult))->toBeTrue()
        ->and(isolation_visible_ids(Student::class, $world->superAdmin))->toContain($world->studentA->id)
        ->and(isolation_visible_ids(Student::class, $world->superAdmin))->toContain($world->studentB->id)
        ->and(isolation_visible_ids(Invoice::class, $world->superAdmin))->toContain($world->invoiceB->id)
        ->and(isolation_visible_ids(Result::class, $world->superAdmin))->toContain($world->draftResult->id)
        ->and($world->superAdmin->hasRole(Role::Student))->toBeTrue();
});

it('shows an inactive account nothing', function () {
    $world = IsolationWorld::make();
    $world->userA->status = UserStatus::Suspended;
    $world->userA->save();

    expect(isolation_visible_ids(Invoice::class, $world->userA))->toBe([])
        ->and(Gate::forUser($world->userA)->allows('view', $world->invoiceA))->toBeFalse()
        ->and(Gate::forUser($world->userA)->allows('download', $world->documentA))->toBeFalse();
});

it('does not add a query per invoice row', function () {
    $student = Student::factory()->create();
    $user = User::query()->findOrFail($student->user_id);
    Invoice::factory()->count(5)->create([
        'student_id' => $student->id,
        'session_id' => $student->entry_session_id,
    ]);

    $user->roles();
    DB::flushQueryLog();
    DB::enableQueryLog();
    expect(Invoice::query()->visibleTo($user)->get())->toHaveCount(5)
        ->and(count(DB::getQueryLog()))->toBe(1);

    Invoice::factory()->create([
        'student_id' => $student->id,
        'session_id' => $student->entry_session_id,
    ]);
    DB::flushQueryLog();
    expect(Invoice::query()->visibleTo($user)->get())->toHaveCount(6)
        ->and(count(DB::getQueryLog()))->toBe(1);

    $fresh = User::query()->findOrFail($user->id);
    DB::flushQueryLog();
    Invoice::query()->visibleTo($fresh)->get();
    expect(count(DB::getQueryLog()))->toBe(3);
});
