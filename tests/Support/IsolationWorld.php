<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\DocumentOwner;
use App\Enums\RegistrationStatus;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Models\Applicant;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\Department;
use App\Models\Document;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Programme;
use App\Models\Receipt;
use App\Models\Result;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;

/**
 * Two faculties and the rows the isolation suite compares.
 *
 * Student A is in faculty A and has an Approved registration for the
 * lecturer's assigned course and semester. Student B is in faculty B.
 */
final class IsolationWorld
{
    public Faculty $facultyA;

    public Faculty $facultyB;

    public Department $departmentA;

    public Department $departmentB;

    public Programme $programmeA;

    public Programme $programmeB;

    public Programme $programmeExtra;

    public Course $courseAssigned;

    public Course $courseSubmitted;

    public Course $courseApproved;

    public Course $coursePublished;

    public Course $courseOther;

    public Course $courseOutside;

    public Semester $semester;

    public Semester $otherSemester;

    public Student $studentA;

    public Student $studentB;

    public Student $draftStudent;

    public Student $submittedStudent;

    public Student $rejectedStudent;

    public Student $otherSemesterStudent;

    public User $userA;

    public User $userB;

    public User $facultyAdmin;

    public User $departmentOfficer;

    public User $lecturer;

    public User $registrar;

    public User $bursar;

    public User $examOfficer;

    public User $superAdmin;

    public CourseRegistration $registrationA;

    public CourseRegistration $registrationB;

    public Invoice $invoiceA;

    public Invoice $invoiceB;

    public Payment $paymentA;

    public Payment $paymentB;

    public Receipt $receiptA;

    public Receipt $receiptB;

    public Result $draftResult;

    public Result $otherSemesterDraft;

    public Result $submittedResult;

    public Result $approvedResult;

    public Result $publishedResult;

    public Result $otherPublished;

    public Result $otherDraft;

    public Result $otherSubmitted;

    public AttendanceRecord $attendanceA;

    public AttendanceRecord $attendanceOtherSemester;

    public AttendanceRecord $attendanceB;

    public Document $documentA;

    public Document $documentB;

    public Document $applicantDocument;

    public Notification $notificationA;

    public Notification $notificationB;

    /**
     * Build the two-faculty fixture.
     */
    public static function make(): self
    {
        $world = new self;
        $world->buildStructure();
        $world->buildPeople();
        $world->buildRegistrations();
        $world->buildFinance();
        $world->buildResults();
        $world->buildAttendance();
        $world->buildDocuments();
        $world->buildNotifications();

        return $world;
    }

    /**
     * Faculties, departments, programmes, courses, and semesters.
     */
    private function buildStructure(): void
    {
        $this->facultyA = Faculty::factory()->create();
        $this->facultyB = Faculty::factory()->create();
        $this->departmentA = Department::factory()->create(['faculty_id' => $this->facultyA->id]);
        $this->departmentB = Department::factory()->create(['faculty_id' => $this->facultyB->id]);
        $this->programmeA = Programme::factory()->create(['department_id' => $this->departmentA->id]);
        $this->programmeExtra = Programme::factory()->create(['department_id' => $this->departmentA->id]);
        $this->programmeB = Programme::factory()->create(['department_id' => $this->departmentB->id]);
        $this->courseAssigned = Course::factory()->create(['department_id' => $this->departmentA->id]);
        $this->courseSubmitted = Course::factory()->create(['department_id' => $this->departmentA->id]);
        $this->courseApproved = Course::factory()->create(['department_id' => $this->departmentA->id]);
        $this->coursePublished = Course::factory()->create(['department_id' => $this->departmentA->id]);
        $this->courseOther = Course::factory()->create(['department_id' => $this->departmentB->id]);
        $this->courseOutside = Course::factory()->create(['department_id' => $this->departmentB->id]);
        $this->semester = Semester::factory()->create();
        $this->otherSemester = Semester::factory()->create();
    }

    /**
     * Students, staff roles, and the lecturer assignment.
     */
    private function buildPeople(): void
    {
        $this->studentA = Student::factory()->create(['programme_id' => $this->programmeA->id]);
        $this->studentB = Student::factory()->create(['programme_id' => $this->programmeB->id]);
        $this->draftStudent = Student::factory()->create(['programme_id' => $this->programmeA->id]);
        $this->submittedStudent = Student::factory()->create(['programme_id' => $this->programmeA->id]);
        $this->rejectedStudent = Student::factory()->create(['programme_id' => $this->programmeA->id]);
        $this->otherSemesterStudent = Student::factory()->create(['programme_id' => $this->programmeA->id]);
        $this->userA = User::query()->findOrFail($this->studentA->user_id);
        $this->userB = User::query()->findOrFail($this->studentB->user_id);
        $this->facultyAdmin = $this->staffUser(Role::FacultyAdmin, $this->facultyA->id, null);
        $this->departmentOfficer = $this->staffUser(Role::DepartmentOfficer, null, $this->departmentA->id);
        $this->lecturer = $this->staffUser(Role::Lecturer, null, null);
        $this->registrar = $this->staffUser(Role::Registrar, null, null);
        $this->bursar = $this->staffUser(Role::Bursar, null, null);
        $this->examOfficer = $this->staffUser(Role::ExamOfficer, null, null);
        $this->superAdmin = $this->staffUser(Role::SuperAdmin, null, null);
        Student::factory()->create([
            'user_id' => $this->superAdmin->id,
            'programme_id' => $this->programmeA->id,
        ]);

        $staff = Staff::factory()->create([
            'user_id' => $this->lecturer->id,
            'department_id' => $this->departmentA->id,
        ]);
        CourseAssignment::factory()->create([
            'staff_id' => $staff->id,
            'course_id' => $this->courseAssigned->id,
            'semester_id' => $this->semester->id,
        ]);
    }

    /**
     * Approved, draft, submitted, and rejected registrations.
     */
    private function buildRegistrations(): void
    {
        $this->registrationA = $this->registration(
            $this->studentA,
            $this->semester,
            $this->courseAssigned,
            RegistrationStatus::Approved,
        );
        $this->registrationB = $this->registration(
            $this->studentB,
            $this->semester,
            $this->courseOther,
            RegistrationStatus::Approved,
        );
        $this->registration($this->draftStudent, $this->semester, $this->courseAssigned, RegistrationStatus::Draft);
        $this->registration(
            $this->submittedStudent,
            $this->semester,
            $this->courseAssigned,
            RegistrationStatus::Submitted,
        );
        $this->registration(
            $this->rejectedStudent,
            $this->semester,
            $this->courseAssigned,
            RegistrationStatus::Rejected,
        );
        $this->registration(
            $this->otherSemesterStudent,
            $this->otherSemester,
            $this->courseAssigned,
            RegistrationStatus::Approved,
        );
    }

    /**
     * One invoice, payment, and receipt for each of the two students.
     */
    private function buildFinance(): void
    {
        $this->invoiceA = Invoice::factory()->create([
            'student_id' => $this->studentA->id,
            'session_id' => $this->studentA->entry_session_id,
        ]);
        $this->invoiceB = Invoice::factory()->create([
            'student_id' => $this->studentB->id,
            'session_id' => $this->studentB->entry_session_id,
        ]);
        $this->paymentA = Payment::factory()->successful()->create([
            'invoice_id' => $this->invoiceA->id,
            'student_id' => $this->studentA->id,
        ]);
        $this->paymentB = Payment::factory()->successful()->create([
            'invoice_id' => $this->invoiceB->id,
            'student_id' => $this->studentB->id,
        ]);
        $this->receiptA = Receipt::factory()->create(['payment_id' => $this->paymentA->id]);
        $this->receiptB = Receipt::factory()->create(['payment_id' => $this->paymentB->id]);
    }

    /**
     * Draft, submitted, approved, and published results.
     */
    private function buildResults(): void
    {
        $this->draftResult = $this->result(
            $this->studentA,
            $this->courseAssigned,
            $this->semester,
            ResultStatus::Draft,
        );
        $this->otherSemesterDraft = $this->result(
            $this->studentA,
            $this->courseAssigned,
            $this->otherSemester,
            ResultStatus::Draft,
        );
        $this->submittedResult = $this->result(
            $this->studentA,
            $this->courseSubmitted,
            $this->semester,
            ResultStatus::Submitted,
        );
        $this->approvedResult = $this->result(
            $this->studentA,
            $this->courseApproved,
            $this->semester,
            ResultStatus::Approved,
        );
        $this->publishedResult = $this->result(
            $this->studentA,
            $this->coursePublished,
            $this->semester,
            ResultStatus::Published,
        );
        $this->otherPublished = $this->result(
            $this->studentB,
            $this->courseOther,
            $this->semester,
            ResultStatus::Published,
        );
        $this->otherDraft = $this->result(
            $this->studentB,
            $this->courseOther,
            $this->otherSemester,
            ResultStatus::Draft,
        );
        $this->otherSubmitted = $this->result(
            $this->studentB,
            $this->courseOutside,
            $this->semester,
            ResultStatus::Submitted,
        );
    }

    /**
     * Marks on the assigned meeting, another semester, and the other course.
     */
    private function buildAttendance(): void
    {
        $this->attendanceA = $this->attendance($this->courseAssigned, $this->semester, $this->studentA);
        $this->attendanceOtherSemester = $this->attendance($this->courseAssigned, $this->otherSemester, $this->studentA);
        $this->attendanceB = $this->attendance($this->courseOther, $this->semester, $this->studentB);
    }

    /**
     * Student files and one applicant file.
     */
    private function buildDocuments(): void
    {
        $this->documentA = Document::factory()->create([
            'owner_type' => DocumentOwner::Student,
            'owner_id' => $this->studentA->id,
        ]);
        $this->documentB = Document::factory()->create([
            'owner_type' => DocumentOwner::Student,
            'owner_id' => $this->studentB->id,
        ]);
        $applicant = Applicant::factory()->create(['programme_id' => $this->programmeA->id]);
        $this->applicantDocument = Document::factory()->forApplicant()->create([
            'owner_id' => $applicant->id,
        ]);
    }

    /**
     * One notification for each student account.
     */
    private function buildNotifications(): void
    {
        $this->notificationA = Notification::factory()->create(['user_id' => $this->userA->id]);
        $this->notificationB = Notification::factory()->create(['user_id' => $this->userB->id]);
    }

    /**
     * An active staff account with one role assignment.
     */
    private function staffUser(Role $role, ?int $facultyId, ?int $departmentId): User
    {
        $user = User::factory()->create();
        RoleAssignment::factory()->create([
            'user_id' => $user->id,
            'role' => $role,
            'faculty_id' => $facultyId,
            'department_id' => $departmentId,
        ]);

        return $user;
    }

    /**
     * A registration and one course item.
     */
    private function registration(
        Student $student,
        Semester $semester,
        Course $course,
        RegistrationStatus $status,
    ): CourseRegistration {
        $factory = match ($status) {
            RegistrationStatus::Draft => CourseRegistration::factory(),
            RegistrationStatus::Submitted => CourseRegistration::factory()->submitted(),
            RegistrationStatus::Approved => CourseRegistration::factory()->approved(),
            RegistrationStatus::Rejected => CourseRegistration::factory()->rejected(),
        };
        $registration = $factory->create([
            'student_id' => $student->id,
            'semester_id' => $semester->id,
        ]);
        CourseRegistrationItem::factory()->create([
            'course_registration_id' => $registration->id,
            'course_id' => $course->id,
        ]);

        return $registration;
    }

    /**
     * One result for a student, course, and semester.
     */
    private function result(Student $student, Course $course, Semester $semester, ResultStatus $status): Result
    {
        $factory = match ($status) {
            ResultStatus::Draft => Result::factory(),
            ResultStatus::Submitted => Result::factory()->submitted(),
            ResultStatus::Approved => Result::factory()->approved(),
            ResultStatus::Published => Result::factory()->published(),
        };

        return $factory->create([
            'student_id' => $student->id,
            'course_id' => $course->id,
            'semester_id' => $semester->id,
            'entered_by' => $this->lecturer->id,
        ]);
    }

    /**
     * One mark for a student at a course meeting.
     */
    private function attendance(Course $course, Semester $semester, Student $student): AttendanceRecord
    {
        $session = AttendanceSession::factory()->create([
            'course_id' => $course->id,
            'semester_id' => $semester->id,
            'created_by' => $this->lecturer->id,
        ]);

        return AttendanceRecord::factory()->create([
            'attendance_session_id' => $session->id,
            'student_id' => $student->id,
        ]);
    }
}
