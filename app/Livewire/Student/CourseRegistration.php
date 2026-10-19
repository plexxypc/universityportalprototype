<?php

declare(strict_types=1);

namespace App\Livewire\Student;

use App\Enums\RegistrationStatus;
use App\Models\CourseRegistration as Registration;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Services\RegistrationService;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

/**
 * Course registration for the signed-in student.
 *
 * The only public state is the elective course ids the student ticks.
 * The student, semester, status, totals, and locked cores are read again
 * on the server. Every action calls RegistrationService.
 */
class CourseRegistration extends Component
{
    /**
     * Elective course ids the student has ticked.
     *
     * @var list<int|string>
     */
    public array $elective_ids = [];

    /**
     * Tick the electives already stored for this student.
     */
    public function mount(RegistrationService $registration): void
    {
        $this->elective_ids = $this->savedElectiveIds($registration);
    }

    /**
     * Save a draft. Units and cores are decided by the service.
     */
    public function saveDraft(RegistrationService $registration): void
    {
        $this->run($registration, function (RegistrationService $registration, User $user): void {
            $registration->saveDraft($user, $this->courseIds(), request());
        }, 'Draft saved.');
    }

    /**
     * Submit the registration. The service is idempotent for the same courses.
     */
    public function submitRegistration(RegistrationService $registration): void
    {
        $this->run($registration, function (RegistrationService $registration, User $user): void {
            $registration->submit($user, $this->courseIds(), request());
        }, 'Registration submitted.');
    }

    /**
     * Add or drop during the add/drop window.
     */
    public function saveChanges(RegistrationService $registration): void
    {
        $this->run($registration, function (RegistrationService $registration, User $user): void {
            $registration->addDrop($user, $this->courseIds(), request());
        }, 'Courses updated.');
    }

    /**
     * Render the window, courses, and the row loaded on the server.
     */
    public function render(RegistrationService $registration): View
    {
        $student = CurrentStudent::require();
        $assessment = $registration->assess($student);
        $row = $this->currentRow($student);
        $status = $row instanceof Registration ? $row->status : null;
        $can_edit_start = $assessment['can_start'] && $this->canStartFrom($status);
        $can_edit_drop = $assessment['can_add_drop'] && $row instanceof Registration;
        $rejection_reason = null;

        if ($row instanceof Registration && $status === RegistrationStatus::Rejected) {
            $rejection_reason = $row->rejection_reason;
        }

        return view('livewire.student.course-registration', [
            'assessment' => $assessment,
            'status' => $status,
            'rejection_reason' => $rejection_reason,
            'total_units' => $this->selectedUnits($assessment['courses']),
            'can_edit_start' => $can_edit_start,
            'can_edit_drop' => $can_edit_drop,
            'editable' => $can_edit_start || $can_edit_drop,
            'slip_ready' => $status === RegistrationStatus::Approved && Route::has('student.course-registration.slip'),
        ]);
    }

    /**
     * Call one service method and show only a domain sentence.
     */
    private function run(RegistrationService $registration, callable $action, string $notice): void
    {
        try {
            $student = CurrentStudent::require();
            $user = $student->user;

            if (! $user instanceof User) {
                throw ValidationException::withMessages([
                    'registration' => 'That registration could not be saved.',
                ]);
            }

            $action($registration, $user);
        } catch (HttpResponseException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'registration' => 'That registration could not be saved.',
            ]);
        }

        session()->flash('registration_notice', $notice);
    }

    /**
     * Elective ids already on the student's registration.
     *
     * @return list<int>
     */
    private function savedElectiveIds(RegistrationService $registration): array
    {
        $student = CurrentStudent::require();
        $assessment = $registration->assess($student);
        $locked = [];

        foreach ($assessment['courses'] as $course) {
            if ($course['locked'] === true) {
                $locked[(int) $course['course_id']] = true;
            }
        }

        $row = $this->currentRow($student);

        if (! $row instanceof Registration) {
            return [];
        }

        $ids = [];

        foreach ($row->items as $item) {
            $course_id = (int) $item->course_id;

            if (! isset($locked[$course_id])) {
                $ids[] = $course_id;
            }
        }

        return $ids;
    }

    /**
     * The signed-in student's registration for the active semester.
     */
    private function currentRow(Student $student): ?Registration
    {
        $semester_id = Semester::query()->active()->value('id');

        if (! is_numeric($semester_id)) {
            return null;
        }

        return Registration::query()
            ->where('student_id', $student->getKey())
            ->where('semester_id', (int) $semester_id)
            ->with('items')
            ->first();
    }

    /**
     * Draft and rejected rows can still be started. A missing row can too.
     */
    private function canStartFrom(?RegistrationStatus $status): bool
    {
        return $status === null
            || $status === RegistrationStatus::Draft
            || $status === RegistrationStatus::Rejected;
    }

    /**
     * Course ids from the public list. Units are not accepted from the client.
     *
     * @return list<int>
     */
    private function courseIds(): array
    {
        $ids = [];

        foreach ($this->elective_ids as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Units for the locked cores plus the ticked electives.
     *
     * @param  list<array{course_id: int, code: string, title: string, credit_units: int, type: string, locked: bool, prerequisites_met: bool}>  $courses
     */
    private function selectedUnits(array $courses): int
    {
        $chosen = [];

        foreach ($this->courseIds() as $id) {
            $chosen[$id] = true;
        }

        $total = 0;

        foreach ($courses as $course) {
            if ($course['locked'] === true) {
                $total += (int) $course['credit_units'];

                continue;
            }

            $course_id = (int) $course['course_id'];

            if (isset($chosen[$course_id]) && $course['prerequisites_met'] === true) {
                $total += (int) $course['credit_units'];
            }
        }

        return $total;
    }
}
