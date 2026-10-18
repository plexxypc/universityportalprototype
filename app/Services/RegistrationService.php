<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CourseType;
use App\Enums\ResultStatus;
use App\Enums\StudentStatus;
use App\Models\Course;
use App\Models\ProgrammeCourse;
use App\Models\Result;
use App\Models\Semester;
use App\Models\Student;
use App\Support\Dates;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Course registration rules.
 *
 * The student and the semester come from the caller. A later write copies
 * credit units and recomputes the total. This class does not take either
 * from a request.
 */
final class RegistrationService
{
    public const string MESSAGE_NOT_ACTIVE = 'Only an active student can register. Contact the Registrar.';

    public const string MESSAGE_NO_SEMESTER = 'There is no active semester.';

    public const string MESSAGE_NO_SEMESTER_NO = 'This semester has no semester number. Ask the Registrar to set it.';

    public const string MESSAGE_NO_DEADLINE = 'Registration is closed because this semester has no registration deadline.';

    public const string MESSAGE_CORE_OVER_MAX = 'Your compulsory courses are above the maximum. Contact the Registrar.';

    public const string MESSAGE_BELOW_MIN = 'The total is below the minimum units.';

    public const string MESSAGE_ABOVE_MAX = 'The total is above the maximum units.';

    public const string MESSAGE_PREREQUISITE = 'A prerequisite has not been passed.';

    public const string MESSAGE_NOT_ELIGIBLE = 'That course is not available for this programme and level.';

    public const string MESSAGE_PAYMENT = 'Minimum payment before registration is switched on, and that rule is not available yet.';

    public const string MESSAGE_PAYMENT_UNAVAILABLE = 'This rule is not available yet. Turning it on blocks all registrations.';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Whether the minimum-payment switch allows registration.
     *
     * Phase 10 replaces this body once a payment is defined. An on switch
     * blocks registration. Invoices and payments are not read.
     */
    public function minimumPaymentSatisfied(Student $student): bool
    {
        if (! $student->exists) {
            return false;
        }

        return ! $this->settings->effective()['require_minimum_payment'];
    }

    /**
     * Registration and add/drop windows for one semester.
     *
     * Registration is open while now is strictly before the deadline.
     * Add/drop is open from that deadline until, but not including, the
     * add/drop deadline. A reopen instant is open while now is strictly
     * before it. A missing registration deadline is closed.
     *
     * @return array{
     *     registration_open: bool,
     *     add_drop_open: bool,
     *     reopened: bool,
     *     registration_closes_at: ?string,
     *     add_drop_closes_at: ?string
     * }
     */
    public function window(Semester $semester, ?Carbon $reopened_until = null, ?Carbon $now = null): array
    {
        $clock = $this->clock($now);
        $registration = $this->storedClock($semester->registration_deadline);
        $add_drop = $this->storedClock($semester->add_drop_deadline);
        $reopen = $this->storedClock($reopened_until);

        return [
            'registration_open' => $registration !== null && $clock < $registration,
            'add_drop_open' => $registration !== null && $add_drop !== null && $clock >= $registration && $clock < $add_drop,
            'reopened' => $reopen !== null && $clock < $reopen,
            'registration_closes_at' => $registration === null ? null : self::closesAt('Registration', $semester->registration_deadline),
            'add_drop_closes_at' => $add_drop === null ? null : self::closesAt('Add/drop', $semester->add_drop_deadline),
        ];
    }

    /**
     * Eligible courses and the window for this student.
     *
     * @return array{
     *     state: string,
     *     message: ?string,
     *     can_start: bool,
     *     can_add_drop: bool,
     *     min_units: int,
     *     max_units: int,
     *     core_units: int,
     *     core_exceeds_maximum: bool,
     *     registration_closes_at: ?string,
     *     add_drop_closes_at: ?string,
     *     courses: list<array{course_id: int, code: string, title: string, credit_units: int, type: string, locked: bool, prerequisites_met: bool}>
     * }
     */
    public function assess(Student $student, ?Carbon $now = null): array
    {
        $limits = $this->limits();
        $empty = $this->emptyAssessment($limits);

        if ($student->status !== StudentStatus::Active) {
            $empty['state'] = 'not_active';
            $empty['message'] = self::MESSAGE_NOT_ACTIVE;

            return $empty;
        }

        $semester = Semester::query()->active()->first();

        if (! $semester instanceof Semester) {
            $empty['state'] = 'no_semester';
            $empty['message'] = self::MESSAGE_NO_SEMESTER;

            return $empty;
        }

        if ($semester->semesterNumber() === null) {
            $empty['state'] = 'no_semester_no';
            $empty['message'] = self::MESSAGE_NO_SEMESTER_NO;

            return $empty;
        }

        $courses = $this->coursesFor($student, $semester);
        $core_units = $this->coreUnits($courses);
        $registration = $student->courseRegistrations()
            ->where('semester_id', $semester->getKey())
            ->first();
        $reopened_until = $registration?->reopened_until;
        $window = $this->window($semester, $reopened_until instanceof Carbon ? $reopened_until : null, $now);
        $payment_blocks = ! $this->minimumPaymentSatisfied($student);
        $can_start = ($window['registration_open'] || $window['reopened']) && ! $payment_blocks;
        $can_add_drop = ($window['add_drop_open'] || $window['reopened']) && ! $payment_blocks;
        $message = $this->windowMessage($semester, $window, $payment_blocks);

        return [
            'state' => $this->state($semester, $window, $payment_blocks),
            'message' => $message,
            'can_start' => $can_start,
            'can_add_drop' => $can_add_drop,
            'min_units' => $limits['min_units'],
            'max_units' => $limits['max_units'],
            'core_units' => $core_units,
            'core_exceeds_maximum' => $core_units > $limits['max_units'],
            'registration_closes_at' => $window['registration_closes_at'],
            'add_drop_closes_at' => $window['add_drop_closes_at'],
            'courses' => $courses,
        ];
    }

    /**
     * Core courses plus the chosen electives, with catalogue units copied.
     *
     * A course id outside the eligible set is refused. Unmet prerequisites
     * are refused. Limits are checked only when $enforce_units is true.
     *
     * @param  list<int>  $course_ids
     * @return list<array{course_id: int, credit_units: int}>
     */
    public function resolveSelection(Student $student, array $course_ids, bool $enforce_units, ?Carbon $now = null): array
    {
        $assessment = $this->assess($student, $now);

        if ($assessment['state'] === 'not_active') {
            throw ValidationException::withMessages(['student' => self::MESSAGE_NOT_ACTIVE]);
        }

        if (! $assessment['can_start'] && ! $assessment['can_add_drop']) {
            throw ValidationException::withMessages([
                'registration' => $assessment['message'] ?? self::MESSAGE_NO_DEADLINE,
            ]);
        }

        $by_id = [];

        foreach ($assessment['courses'] as $course) {
            $by_id[$course['course_id']] = $course;
        }

        $chosen = [];

        foreach ($assessment['courses'] as $course) {
            if ($course['locked']) {
                $chosen[$course['course_id']] = $course;
            }
        }

        foreach ($course_ids as $course_id) {
            $id = (int) $course_id;

            if (! isset($by_id[$id])) {
                throw ValidationException::withMessages(['course_id' => self::MESSAGE_NOT_ELIGIBLE]);
            }

            $chosen[$id] = $by_id[$id];
        }

        foreach ($chosen as $course) {
            if (! $course['prerequisites_met']) {
                throw ValidationException::withMessages(['course_id' => self::MESSAGE_PREREQUISITE]);
            }
        }

        $total = 0;
        $items = [];

        foreach ($chosen as $course) {
            $units = $course['credit_units'];
            $total += $units;
            $items[] = [
                'course_id' => $course['course_id'],
                'credit_units' => $units,
            ];
        }

        if ($enforce_units) {
            $problem = $this->unitMessage($total, $assessment['core_units'], $assessment['min_units'], $assessment['max_units']);

            if ($problem !== null) {
                throw ValidationException::withMessages(['total_units' => $problem]);
            }
        }

        return $items;
    }

    /**
     * The unit message for a total, or null when the total is allowed.
     */
    public function unitMessage(int $total, int $core_units, int $min_units, int $max_units): ?string
    {
        if ($core_units > $max_units) {
            return self::MESSAGE_CORE_OVER_MAX;
        }

        if ($total < $min_units) {
            return self::MESSAGE_BELOW_MIN;
        }

        if ($total > $max_units) {
            return self::MESSAGE_ABOVE_MAX;
        }

        return null;
    }

    /**
     * Display sentence. The named instant is when the window closes.
     */
    public static function closesAt(string $label, mixed $instant): string
    {
        return $label.' closes at '.Dates::dateTime($instant).'.';
    }

    /**
     * @param  array{min_units: int, max_units: int}  $limits
     * @return array{
     *     state: string,
     *     message: ?string,
     *     can_start: bool,
     *     can_add_drop: bool,
     *     min_units: int,
     *     max_units: int,
     *     core_units: int,
     *     core_exceeds_maximum: bool,
     *     registration_closes_at: ?string,
     *     add_drop_closes_at: ?string,
     *     courses: list<array{course_id: int, code: string, title: string, credit_units: int, type: string, locked: bool, prerequisites_met: bool}>
     * }
     */
    private function emptyAssessment(array $limits): array
    {
        return [
            'state' => 'closed',
            'message' => null,
            'can_start' => false,
            'can_add_drop' => false,
            'min_units' => $limits['min_units'],
            'max_units' => $limits['max_units'],
            'core_units' => 0,
            'core_exceeds_maximum' => false,
            'registration_closes_at' => null,
            'add_drop_closes_at' => null,
            'courses' => [],
        ];
    }

    /**
     * @return array{min_units: int, max_units: int}
     */
    private function limits(): array
    {
        $effective = $this->settings->effective();

        return [
            'min_units' => (int) $effective['min_units'],
            'max_units' => (int) $effective['max_units'],
        ];
    }

    /**
     * @param  array{registration_open: bool, add_drop_open: bool, reopened: bool, registration_closes_at: ?string, add_drop_closes_at: ?string}  $window
     */
    private function state(Semester $semester, array $window, bool $payment_blocks): string
    {
        if ($payment_blocks) {
            return 'payment_blocked';
        }

        if ($this->storedClock($semester->registration_deadline) === null) {
            return 'closed';
        }

        if ($window['registration_open'] || $window['reopened']) {
            return 'open';
        }

        if ($window['add_drop_open']) {
            return 'add_drop';
        }

        return 'closed';
    }

    /**
     * @param  array{registration_open: bool, add_drop_open: bool, reopened: bool, registration_closes_at: ?string, add_drop_closes_at: ?string}  $window
     */
    private function windowMessage(Semester $semester, array $window, bool $payment_blocks): ?string
    {
        if ($payment_blocks) {
            return self::MESSAGE_PAYMENT;
        }

        if ($this->storedClock($semester->registration_deadline) === null) {
            return self::MESSAGE_NO_DEADLINE;
        }

        if ($window['add_drop_open'] && ! $window['registration_open'] && ! $window['reopened']) {
            return $window['add_drop_closes_at'];
        }

        if (! $window['registration_open'] && ! $window['reopened']) {
            return $window['registration_closes_at'];
        }

        return $window['registration_closes_at'];
    }

    /**
     * @return list<array{course_id: int, code: string, title: string, credit_units: int, type: string, locked: bool, prerequisites_met: bool}>
     */
    private function coursesFor(Student $student, Semester $semester): array
    {
        $mappings = ProgrammeCourse::query()
            ->where('programme_id', $student->programme_id)
            ->where('level', $student->level)
            ->where('semester_no', $semester->semesterNumber())
            ->with(['course.prerequisites'])
            ->get();

        $prerequisite_ids = [];

        foreach ($mappings as $mapping) {
            $course = $mapping->course;

            if (! $course instanceof Course) {
                continue;
            }

            foreach ($course->prerequisites as $link) {
                $prerequisite_ids[] = (int) $link->prerequisite_course_id;
            }
        }

        $passed = $this->passedCourseIds($student, $semester, array_values(array_unique($prerequisite_ids)));
        $courses = [];

        foreach ($mappings as $mapping) {
            $course = $mapping->course;

            if (! $course instanceof Course) {
                continue;
            }

            $met = true;

            foreach ($course->prerequisites as $link) {
                if (! in_array((int) $link->prerequisite_course_id, $passed, true)) {
                    $met = false;
                }
            }

            $courses[] = [
                'course_id' => (int) $course->getKey(),
                'code' => (string) $course->code,
                'title' => (string) $course->title,
                'credit_units' => (int) $course->credit_units,
                'type' => $mapping->type->value,
                'locked' => $mapping->type === CourseType::Core,
                'prerequisites_met' => $met,
            ];
        }

        usort($courses, function (array $left, array $right): int {
            if ($left['locked'] !== $right['locked']) {
                return $left['locked'] ? -1 : 1;
            }

            return $left['code'] <=> $right['code'];
        });

        return $courses;
    }

    /**
     * @param  list<array{locked: bool, credit_units: int}>  $courses
     */
    private function coreUnits(array $courses): int
    {
        $total = 0;

        foreach ($courses as $course) {
            if ($course['locked']) {
                $total += $course['credit_units'];
            }
        }

        return $total;
    }

    /**
     * Published passes in another semester, at or above that result's pass mark.
     *
     * @param  list<int>  $course_ids
     * @return list<int>
     */
    private function passedCourseIds(Student $student, Semester $semester, array $course_ids): array
    {
        if ($course_ids === []) {
            return [];
        }

        $rows = Result::query()
            ->join('grading_schemes', 'grading_schemes.id', '=', 'results.grading_scheme_id')
            ->where('results.student_id', $student->getKey())
            ->where('results.status', ResultStatus::Published->value)
            ->whereNotNull('results.published_at')
            ->where('results.semester_id', '!=', $semester->getKey())
            ->whereIn('results.course_id', $course_ids)
            ->get(['results.course_id as passed_course_id', 'results.total as result_total', 'grading_schemes.pass_mark as scheme_pass_mark']);

        $passed = [];

        foreach ($rows as $row) {
            $total = $this->hundredths($row->getAttribute('result_total'));
            $mark = $this->hundredths($row->getAttribute('scheme_pass_mark'));

            if ($total === null || $mark === null || $total < $mark) {
                continue;
            }

            $passed[] = (int) $row->getAttribute('passed_course_id');
        }

        return array_values(array_unique($passed));
    }

    /**
     * Decimal text with two places, as hundredths. Null when it is not that shape.
     */
    private function hundredths(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $text = (string) $value;

        if (preg_match('/\A(-?\d+)\.(\d{2})\z/', $text, $matches) !== 1) {
            return null;
        }

        $sign = str_starts_with($matches[1], '-') ? -1 : 1;
        $whole = (int) ltrim($matches[1], '-');

        return $sign * (($whole * 100) + (int) $matches[2]);
    }

    /**
     * Lagos wall time to the second.
     */
    private function clock(?Carbon $now): string
    {
        $instant = $now ?? Carbon::now(Dates::TIMEZONE);

        return $instant->copy()->timezone(Dates::TIMEZONE)->format('Y-m-d H:i:s');
    }

    /**
     * A stored instant as Lagos wall time, or null when it is missing.
     */
    private function storedClock(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Dates::storageDateTime($value);
    }
}
