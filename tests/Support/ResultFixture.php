<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\AssessmentComponent;
use App\Models\ClassificationBand;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\Department;
use App\Models\GradeBand;
use App\Models\GradingScheme;
use App\Models\Programme;
use App\Models\RoleAssignment;
use App\Models\Semester;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\Grading\DefaultScheme;
use App\Support\Grading\Hundredths;
use RuntimeException;

/**
 * One course, one inactive semester, and the accounts result tests share.
 *
 * The semester is not active. Entry does not require the active semester.
 */
final class ResultFixture
{
    public AcademicSession $session;

    public Semester $semester;

    public Department $department;

    public Programme $programme;

    public Course $course;

    public GradingScheme $scheme;

    public AssessmentComponent $ca;

    public AssessmentComponent $exam;

    public Student $student;

    public User $studentUser;

    public User $lecturer;

    public User $examOfficer;

    public User $departmentOfficer;

    public User $superAdmin;

    public User $registrar;

    public User $bursar;

    /**
     * Build the course, the scheme, and the accounts.
     */
    public static function make(): self
    {
        $fixture = new self;
        $fixture->session = AcademicSession::factory()->create(['name' => '2026/2027']);
        $fixture->semester = Semester::factory()->create([
            'session_id' => $fixture->session->id,
            'name' => 'First',
            'semester_no' => 1,
            'is_active' => false,
        ]);
        $fixture->department = Department::factory()->create();
        $fixture->programme = Programme::factory()->create([
            'department_id' => $fixture->department->id,
        ]);
        $fixture->course = Course::factory()->create([
            'department_id' => $fixture->department->id,
            'code' => 'CSC201',
            'credit_units' => 3,
        ]);
        $fixture->scheme = GradingScheme::factory()->active()->create([
            'name' => DefaultScheme::NAME,
            'pass_mark' => Hundredths::toDecimal(DefaultScheme::passMarkHundredths()),
            'version' => 1,
        ]);

        foreach (DefaultScheme::components() as $component) {
            $row = AssessmentComponent::factory()->create([
                'scheme_id' => $fixture->scheme->id,
                'name' => $component['name'],
                'max_score' => Hundredths::toDecimal($component['max_hundredths']),
                'sort' => $component['sort'],
            ]);

            if ($component['name'] === 'Continuous Assessment') {
                $fixture->ca = $row;
            }

            if ($component['name'] === 'Exam') {
                $fixture->exam = $row;
            }
        }

        foreach (DefaultScheme::bands() as $band) {
            GradeBand::factory()->create([
                'scheme_id' => $fixture->scheme->id,
                'min_score' => Hundredths::toDecimal($band['min_hundredths']),
                'max_score' => Hundredths::toDecimal($band['max_hundredths']),
                'letter' => $band['letter'],
                'points' => Hundredths::toDecimal($band['points_hundredths']),
                'remark' => $band['remark'],
            ]);
        }

        foreach (DefaultScheme::classifications() as $class) {
            ClassificationBand::factory()->create([
                'scheme_id' => $fixture->scheme->id,
                'name' => $class['name'],
                'min_cgpa' => Hundredths::toDecimal($class['min_hundredths']),
                'max_cgpa' => Hundredths::toDecimal($class['max_hundredths']),
            ]);
        }

        $fixture->student = Student::factory()->create([
            'programme_id' => $fixture->programme->id,
            'entry_session_id' => $fixture->session->id,
            'level' => 200,
        ]);
        $student_user = $fixture->student->user;

        if (! $student_user instanceof User) {
            throw new RuntimeException('The student has no account.');
        }

        $fixture->studentUser = $student_user;
        $fixture->lecturer = $fixture->role(Role::Lecturer, null);
        $fixture->examOfficer = $fixture->role(Role::ExamOfficer, null);
        $fixture->departmentOfficer = $fixture->role(Role::DepartmentOfficer, $fixture->department->id);
        $fixture->superAdmin = $fixture->role(Role::SuperAdmin, null);
        $fixture->registrar = $fixture->role(Role::Registrar, null);
        $fixture->bursar = $fixture->role(Role::Bursar, null);
        $staff = Staff::factory()->create([
            'user_id' => $fixture->lecturer->id,
            'department_id' => $fixture->department->id,
        ]);
        CourseAssignment::factory()->create([
            'staff_id' => $staff->id,
            'course_id' => $fixture->course->id,
            'semester_id' => $fixture->semester->id,
        ]);
        $registration = CourseRegistration::factory()->approved()->create([
            'student_id' => $fixture->student->id,
            'semester_id' => $fixture->semester->id,
            'total_units' => 3,
        ]);
        CourseRegistrationItem::factory()->create([
            'course_registration_id' => $registration->id,
            'course_id' => $fixture->course->id,
            'credit_units' => 3,
        ]);

        return $fixture;
    }

    /**
     * A passing CA and exam.
     *
     * @return array<int, string>
     */
    public function passingScores(): array
    {
        return [
            (int) $this->ca->id => '20.00',
            (int) $this->exam->id => '50.00',
        ];
    }

    /**
     * One staff account. A department officer receives that department.
     */
    private function role(Role $role, ?int $department_id): User
    {
        $user = User::factory()->create();
        RoleAssignment::factory()->create([
            'user_id' => $user->id,
            'role' => $role,
            'faculty_id' => null,
            'department_id' => $department_id,
        ]);

        return $user;
    }
}
