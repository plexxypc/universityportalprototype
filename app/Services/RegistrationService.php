<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CourseType;
use App\Enums\EmailTemplate;
use App\Enums\NotificationType;
use App\Enums\RegistrationStatus;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\ProgrammeCourse;
use App\Models\Result;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use App\Support\Dates;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Course registration rules, draft save, and submit.
 *
 * The student comes from the signed-in account. The semester is the active
 * semester. Credit units are copied from the catalogue, and the total is
 * the sum of those copies.
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

    public const string MESSAGE_TRANSITION = 'That registration cannot change in that way.';

    public const string ACTION_SAVED = 'registration.saved';

    public const string ACTION_SUBMITTED = 'registration.submitted';

    public const string ACTION_APPROVED = 'registration.approved';

    public const string ACTION_REJECTED = 'registration.rejected';

    public const string ACTION_ADD_DROP = 'registration.add_drop';

    public const string ACTION_REOPENED = 'registration.reopened';

    public const string MESSAGE_REOPEN_TIME = 'Choose a reopen time after the current time.';

    public const string MESSAGE_REOPEN_SPAN = 'Choose a reopen time within 14 days.';

    public const int REOPEN_MAX_DAYS = 14;

    public const string MESSAGE_REASON = 'Enter a reason for the rejection.';

    public const string MESSAGE_BULK_CAP = 'Approve at most 100 registrations at a time.';

    public const string MESSAGE_RELOAD = 'This registration changed. Reload the queue and try again.';

    public const string WARNING_EMAIL = 'The registration confirmation email could not be queued.';

    public const int BULK_CAP = 100;

    public const string NOTICE_SUBMITTED_TITLE = 'Registration submitted';

    public const string NOTICE_SUBMITTED_MESSAGE = 'Your course registration has been submitted.';

    public const string NOTICE_CONFIRMED_TITLE = 'Registration confirmed';

    public const string NOTICE_CONFIRMED_MESSAGE = 'Your course registration is confirmed.';

    public const string NOTICE_REJECTED_TITLE = 'Registration not confirmed';

    public const string NOTICE_REJECTED_MESSAGE = 'Your course registration was not confirmed.';

    public const string NOTICE_LINK = '/student/course-registration';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Audit $audit,
        private readonly NotificationService $notifications,
        private readonly MailService $mail,
    ) {}

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

        $items = $this->selectedItems($assessment, $course_ids);

        if ($enforce_units) {
            $total = 0;

            foreach ($items as $item) {
                $total += $item['credit_units'];
            }

            $problem = $this->unitMessage($total, $assessment['core_units'], $assessment['min_units'], $assessment['max_units']);

            if ($problem !== null) {
                throw ValidationException::withMessages(['total_units' => $problem]);
            }
        }

        return $items;
    }

    /**
     * Core courses plus the chosen electives. The window is not checked here.
     *
     * @param  array{courses: list<array{course_id: int, code: string, title: string, credit_units: int, type: string, locked: bool, prerequisites_met: bool}>}  $assessment
     * @param  list<int>  $course_ids
     * @return list<array{course_id: int, credit_units: int}>
     */
    private function selectedItems(array $assessment, array $course_ids): array
    {
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

        $items = [];

        foreach ($chosen as $course) {
            if (! $course['prerequisites_met']) {
                throw ValidationException::withMessages(['course_id' => self::MESSAGE_PREREQUISITE]);
            }

            $items[] = [
                'course_id' => $course['course_id'],
                'credit_units' => $course['credit_units'],
            ];
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
     * Save a draft. Limits are not enforced. A submitted or approved row cannot be edited here.
     *
     * @param  list<int>  $course_ids
     */
    public function saveDraft(User $actor, array $course_ids, Request $request): CourseRegistration
    {
        $this->assertCanSubmit($actor);

        return $this->write($actor, $course_ids, $request, false);
    }

    /**
     * Submit the registration. Limits are enforced. Approval off approves in this same transaction.
     *
     * @param  list<int>  $course_ids
     */
    public function submit(User $actor, array $course_ids, Request $request): CourseRegistration
    {
        $this->assertCanSubmit($actor);

        return $this->write($actor, $course_ids, $request, true);
    }

    /**
     * Hash of the saved total, the saved item units, and the row's updated time.
     *
     * The queue passes this string back. Credit units and the total are not
     * accepted from the client.
     */
    public function version(CourseRegistration $registration): string
    {
        $stamp = $registration->updated_at?->timezone(Dates::TIMEZONE)->format('Y-m-d H:i:s') ?? '';

        return $stamp.'|'.$this->selectionHash($registration);
    }

    /**
     * Approve one submitted registration. A second approve of the same selection writes nothing.
     *
     * A missing id and an id outside this account's scope throw the same 404.
     * The actor cannot approve a registration that belongs to their own student row.
     */
    public function approve(User $actor, int $registration_id, string $version, Request $request): CourseRegistration
    {
        $this->assertCanApprove($actor);

        return $this->decide($actor, $registration_id, $version, $request, true, null);
    }

    /**
     * Reject one submitted registration. The reason stays on the row.
     *
     * A missing id and an id outside this account's scope throw the same 404.
     */
    public function reject(User $actor, int $registration_id, string $reason, string $version, Request $request): CourseRegistration
    {
        $this->assertCanApprove($actor);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['rejection_reason' => self::MESSAGE_REASON]);
        }

        return $this->decide($actor, $registration_id, $version, $request, false, $reason);
    }

    /**
     * Approve the submitted rows in this list. Other ids are skipped.
     *
     * Each entry is the id and the version the queue loaded. A changed row,
     * a row that is not submitted, and the actor's own registration are skipped.
     * The result is two counts. It does not name the skipped ids.
     *
     * @param  list<array{id: int, version: string}>  $previews
     * @return array{approved: int, skipped: int}
     */
    public function bulkApprove(User $actor, array $previews, Request $request): array
    {
        $this->assertCanApprove($actor);

        if (count($previews) > self::BULK_CAP) {
            throw ValidationException::withMessages(['registration_ids' => self::MESSAGE_BULK_CAP]);
        }

        $rows = [];

        foreach ($previews as $preview) {
            $rows[] = [
                'id' => (int) $preview['id'],
                'version' => $preview['version'],
            ];
        }

        $student_ids = [];

        foreach (CourseRegistration::query()->whereIn('id', array_column($rows, 'id'))->get(['id', 'student_id']) as $existing) {
            $student_ids[(int) $existing->getKey()] = (int) $existing->student_id;
        }

        usort($rows, function (array $left, array $right) use ($student_ids): int {
            $left_student = $student_ids[$left['id']] ?? 0;
            $right_student = $student_ids[$right['id']] ?? 0;

            return [$left_student, $left['id']] <=> [$right_student, $right['id']];
        });
        $approved = 0;
        $skipped = 0;

        DB::transaction(function () use ($actor, $rows, $student_ids, $request, &$approved, &$skipped): void {
            foreach ($rows as $row) {
                $student_id = $student_ids[$row['id']] ?? 0;

                if ($student_id > 0) {
                    $this->lockStudent($student_id);
                }

                $registration = CourseRegistration::query()
                    ->visibleTo($actor)
                    ->whereKey($row['id'])
                    ->lockForUpdate()
                    ->first();

                if (! $registration instanceof CourseRegistration
                    || $registration->status !== RegistrationStatus::Submitted
                    || $this->isOwnRegistration($actor, $registration)
                    || ! $this->versionMatches($registration, $row['version'])) {
                    $skipped++;

                    continue;
                }

                $this->markApproved($actor, $registration, $request);
                $approved++;
            }
        });

        return ['approved' => $approved, 'skipped' => $skipped];
    }

    /**
     * Change courses during add/drop. Limits, eligibility, and prerequisites are checked again.
     *
     * An approved row returns to Submitted when approval is required. When approval
     * is off, an approved row stays Approved and no second confirmation is sent.
     *
     * @param  list<int>  $course_ids
     */
    public function addDrop(User $actor, array $course_ids, Request $request): CourseRegistration
    {
        $this->assertCanSubmit($actor);
        $student = $actor->student;

        if (! $student instanceof Student) {
            throw new AuthorizationException;
        }

        $registration = DB::transaction(function () use ($actor, $student, $course_ids, $request): CourseRegistration {
            $student = $this->lockStudent((int) $student->getKey());

            if (! $student instanceof Student) {
                throw new AuthorizationException;
            }

            $semester = Semester::query()->active()->first();

            if (! $semester instanceof Semester) {
                throw ValidationException::withMessages(['registration' => self::MESSAGE_NO_SEMESTER]);
            }

            $registration = $this->lockedRegistration($student, $semester);
            $assessment = $this->assess($student);

            if ($assessment['state'] === 'not_active') {
                throw ValidationException::withMessages(['student' => self::MESSAGE_NOT_ACTIVE]);
            }

            if (! $assessment['can_add_drop']) {
                throw ValidationException::withMessages([
                    'registration' => $assessment['message'] ?? self::MESSAGE_NO_DEADLINE,
                ]);
            }

            $items = $this->selectedItems($assessment, $course_ids);
            $resolved_ids = [];
            $total_units = 0;

            foreach ($items as $item) {
                $resolved_ids[] = $item['course_id'];
                $total_units += $item['credit_units'];
            }

            $problem = $this->unitMessage(
                $total_units,
                $assessment['core_units'],
                $assessment['min_units'],
                $assessment['max_units'],
            );

            if ($problem !== null) {
                throw ValidationException::withMessages(['total_units' => $problem]);
            }

            $approval_required = (bool) $this->settings->effective()['approval_required'];
            $target = $this->addDropTarget($registration, $approval_required);

            if ($registration instanceof CourseRegistration && $this->sameCourseIds($registration, $target, $resolved_ids)) {
                return $registration;
            }

            if ($registration instanceof CourseRegistration && ! $this->addDropAllowed($registration->status, $target)) {
                throw ValidationException::withMessages(['registration' => self::MESSAGE_TRANSITION]);
            }

            $was_approved = $registration instanceof CourseRegistration
                && $registration->status === RegistrationStatus::Approved;
            $before = $registration instanceof CourseRegistration && ! $registration->wasRecentlyCreated
                ? $this->snapshot($registration)
                : null;

            if (! $registration instanceof CourseRegistration) {
                $registration = $this->newRegistration($student, $semester);
                $before = null;
            }

            $this->replaceItems($registration, $items);
            $registration->status = $target;
            $registration->total_units = $total_units;

            if ($target === RegistrationStatus::Submitted) {
                $registration->submitted_at = $registration->submitted_at ?? now();
                $registration->decided_at = null;
                $registration->decided_by = null;
                $registration->rejection_reason = null;
            }

            if ($target === RegistrationStatus::Approved && ! $was_approved) {
                $registration->submitted_at = $registration->submitted_at ?? now();
                $registration->decided_at = now();
                $registration->decided_by = null;
                $registration->rejection_reason = null;
            }

            $registration->save();
            $after = $this->snapshot($registration);

            $this->audit->record(
                (int) $actor->id,
                self::ACTION_ADD_DROP,
                'course_registrations',
                (int) $registration->getKey(),
                $before,
                $after,
                $this->audit->ipFromRequest($request),
            );

            if ($target === RegistrationStatus::Submitted) {
                $this->notifications->create($actor, NotificationType::RegistrationSubmitted->value, [
                    'title' => self::NOTICE_SUBMITTED_TITLE,
                    'message' => self::NOTICE_SUBMITTED_MESSAGE,
                    'link' => self::NOTICE_LINK,
                ]);
            }

            if ($target === RegistrationStatus::Approved && ! $was_approved) {
                $this->notifyApproved($actor, $registration, $semester);
            }

            return $registration;
        });

        return $registration;
    }

    /**
     * Open a registration window for one active student, and create an empty draft when none exists.
     *
     * An existing row keeps its status, total, and items. Only the reopen columns change.
     */
    public function reopen(User $actor, int $student_id, string $reason, Carbon $reopened_until, Request $request): CourseRegistration
    {
        $this->assertCanReopen($actor);
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reopen_reason' => self::MESSAGE_REASON]);
        }

        $until = $this->storedClock($reopened_until);

        $now = $this->clock(null);

        if ($until === null || $now >= $until) {
            throw ValidationException::withMessages(['reopened_until' => self::MESSAGE_REOPEN_TIME]);
        }

        $latest = Carbon::parse($now, Dates::TIMEZONE)->addDays(self::REOPEN_MAX_DAYS)->format('Y-m-d H:i:s');

        if ($until > $latest) {
            throw ValidationException::withMessages(['reopened_until' => self::MESSAGE_REOPEN_SPAN]);
        }

        return DB::transaction(function () use ($actor, $student_id, $reason, $reopened_until, $request, $until): CourseRegistration {
            $student = $this->lockStudent($student_id);

            if (! $student instanceof Student) {
                throw new NotFoundHttpException;
            }

            if ($student->status !== StudentStatus::Active) {
                throw ValidationException::withMessages(['student' => self::MESSAGE_NOT_ACTIVE]);
            }

            $semester = Semester::query()->active()->first();

            if (! $semester instanceof Semester) {
                throw ValidationException::withMessages(['registration' => self::MESSAGE_NO_SEMESTER]);
            }

            $registration = $this->lockedRegistration($student, $semester);
            $before = $registration instanceof CourseRegistration ? $this->snapshot($registration) : null;

            if (! $registration instanceof CourseRegistration) {
                $registration = $this->newRegistration($student, $semester);
                $before = null;
            }

            $registration->reopened_until = $reopened_until;
            $registration->reopen_reason = $reason;
            $registration->reopened_by = $actor->id;
            $registration->save();

            /** @var array<string, int|string> $after */
            $after = $this->snapshot($registration);
            $after['reopened_until'] = $until;

            $this->audit->record(
                (int) $actor->id,
                self::ACTION_REOPENED,
                'course_registrations',
                (int) $registration->getKey(),
                $before,
                $after,
                $this->audit->ipFromRequest($request),
            );

            return $registration;
        });
    }

    /**
     * Display sentence. The named instant is when the window closes.
     */
    public static function closesAt(string $label, mixed $instant): string
    {
        return $label.' closes at '.Dates::dateTime($instant).'.';
    }

    /**
     * The signed-in student is the only account that may save or submit.
     */
    private function assertCanSubmit(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('course_registration.submit') || ! $actor->student instanceof Student) {
            throw new AuthorizationException;
        }
    }

    /**
     * Registrar, Super Admin, and the scoped officers may approve.
     */
    private function assertCanApprove(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('course_registration.approve')) {
            throw new AuthorizationException;
        }
    }

    /**
     * Only a Registrar or a Super Admin may reopen one student.
     */
    private function assertCanReopen(User $actor): void
    {
        if ($actor->status !== UserStatus::Active) {
            throw new AuthorizationException;
        }

        if (! $actor->hasRole(Role::Registrar) && ! $actor->hasRole(Role::SuperAdmin)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Approval sends an approved row back to the queue. Otherwise it stays approved.
     */
    private function addDropTarget(?CourseRegistration $registration, bool $approval_required): RegistrationStatus
    {
        if ($registration instanceof CourseRegistration
            && $registration->status === RegistrationStatus::Approved
            && ! $approval_required) {
            return RegistrationStatus::Approved;
        }

        if ($approval_required) {
            return RegistrationStatus::Submitted;
        }

        return RegistrationStatus::Approved;
    }

    /**
     * Add/drop may move a draft, a rejection, a submission, or an approval.
     */
    private function addDropAllowed(RegistrationStatus $from, RegistrationStatus $to): bool
    {
        return match ($from) {
            RegistrationStatus::Draft, RegistrationStatus::Rejected, RegistrationStatus::Submitted, RegistrationStatus::Approved => $to === RegistrationStatus::Submitted || $to === RegistrationStatus::Approved,
        };
    }

    /**
     * Approve or reject one visible submitted row.
     *
     * A refused transition writes nothing. A row that changed after the queue
     * loaded it is refused with a reload message.
     */
    private function decide(
        User $actor,
        int $registration_id,
        string $version,
        Request $request,
        bool $approving,
        ?string $reason,
    ): CourseRegistration {
        return DB::transaction(function () use ($actor, $registration_id, $version, $request, $approving, $reason): CourseRegistration {
            $registration = $this->lockedVisible($actor, $registration_id);

            if ($this->isOwnRegistration($actor, $registration)) {
                throw new AuthorizationException;
            }

            if ($registration->status === RegistrationStatus::Approved && $this->sameSelection($registration, $version)) {
                return $registration;
            }

            if (! $this->versionMatches($registration, $version)) {
                throw ValidationException::withMessages(['registration' => self::MESSAGE_RELOAD]);
            }

            if ($registration->status !== RegistrationStatus::Submitted) {
                throw ValidationException::withMessages(['registration' => self::MESSAGE_TRANSITION]);
            }

            if ($approving) {
                $this->markApproved($actor, $registration, $request);

                return $registration;
            }

            $this->markRejected($actor, $registration, $request, (string) $reason);

            return $registration;
        });
    }

    /**
     * Whether this registration belongs to the actor's own student row.
     */
    private function isOwnRegistration(User $actor, CourseRegistration $registration): bool
    {
        $registration->loadMissing('student');
        $owner = $registration->student?->user_id;

        return $owner !== null && (int) $owner === (int) $actor->id;
    }

    /**
     * Whether the queue's version still matches this locked row.
     */
    private function versionMatches(CourseRegistration $registration, string $version): bool
    {
        return hash_equals($this->version($registration), $version);
    }

    /**
     * Whether the saved courses and units still match the loaded version.
     *
     * Approval itself changes the row's updated time. A second approve of the
     * same selection writes nothing.
     */
    private function sameSelection(CourseRegistration $registration, string $version): bool
    {
        $part = $this->selectionPart($version);

        return $part !== null && hash_equals($this->selectionHash($registration), $part);
    }

    /**
     * The selection half of a version string, or null when the string is not one.
     */
    private function selectionPart(string $version): ?string
    {
        $pieces = explode('|', $version, 2);

        if (count($pieces) !== 2 || $pieces[1] === '') {
            return null;
        }

        return $pieces[1];
    }

    /**
     * Hash of the stored total and each stored course id with its copied units.
     */
    private function selectionHash(CourseRegistration $registration): string
    {
        $lines = [(string) (int) $registration->total_units];
        $items = $registration->items()->orderBy('course_id')->get(['course_id', 'credit_units']);

        foreach ($items as $item) {
            $lines[] = (int) $item->course_id.':'.(int) $item->credit_units;
        }

        return hash('sha256', implode('|', $lines));
    }

    /**
     * The registration this account may decide, or a 404 when it is missing or hidden.
     */
    private function lockedVisible(User $actor, int $registration_id): CourseRegistration
    {
        $student_id = CourseRegistration::query()->whereKey($registration_id)->value('student_id');

        if (is_numeric($student_id)) {
            $this->lockStudent((int) $student_id);
        }

        $registration = CourseRegistration::query()
            ->visibleTo($actor)
            ->whereKey($registration_id)
            ->lockForUpdate()
            ->first();

        if (! $registration instanceof CourseRegistration) {
            throw new NotFoundHttpException;
        }

        return $registration;
    }

    /**
     * Set Approved, audit, and send the confirmation once.
     */
    private function markApproved(User $actor, CourseRegistration $registration, Request $request): void
    {
        $before = $this->snapshot($registration);
        $registration->status = RegistrationStatus::Approved;
        $registration->decided_at = now();
        $registration->decided_by = $actor->id;
        $registration->rejection_reason = null;
        $registration->save();

        $this->audit->record(
            (int) $actor->id,
            self::ACTION_APPROVED,
            'course_registrations',
            (int) $registration->getKey(),
            $before,
            $this->snapshot($registration),
            $this->audit->ipFromRequest($request),
        );
        $this->notifyStudent($registration, true);
    }

    /**
     * Set Rejected. The reason is stored on the row and not in the notice.
     */
    private function markRejected(User $actor, CourseRegistration $registration, Request $request, string $reason): void
    {
        $before = $this->snapshot($registration);
        $registration->status = RegistrationStatus::Rejected;
        $registration->decided_at = now();
        $registration->decided_by = $actor->id;
        $registration->rejection_reason = $reason;
        $registration->save();

        $this->audit->record(
            (int) $actor->id,
            self::ACTION_REJECTED,
            'course_registrations',
            (int) $registration->getKey(),
            $before,
            $this->snapshot($registration),
            $this->audit->ipFromRequest($request),
        );
        $this->notifyStudent($registration, false);
    }

    /**
     * Tell the student. Rejection text does not include the reason.
     */
    private function notifyStudent(CourseRegistration $registration, bool $approved): void
    {
        $registration->loadMissing(['student.user', 'semester.academicSession']);
        $recipient = $registration->student?->user;
        $semester = $registration->semester;

        if (! $recipient instanceof User || ! $semester instanceof Semester) {
            throw ValidationException::withMessages(['registration' => self::MESSAGE_TRANSITION]);
        }

        if ($approved) {
            $this->notifyApproved($recipient, $registration, $semester);

            return;
        }

        $this->notifications->create($recipient, NotificationType::RegistrationRejected->value, [
            'title' => self::NOTICE_REJECTED_TITLE,
            'message' => self::NOTICE_REJECTED_MESSAGE,
            'link' => self::NOTICE_LINK,
        ]);
    }

    /**
     * Replace the items, or return the row unchanged when the submit is repeated.
     *
     * @param  list<int>  $course_ids
     */
    private function write(User $actor, array $course_ids, Request $request, bool $submitting): CourseRegistration
    {
        $student = $actor->student;

        if (! $student instanceof Student) {
            throw new AuthorizationException;
        }

        $registration = DB::transaction(function () use ($actor, $student, $course_ids, $request, $submitting): CourseRegistration {
            $student = $this->lockStudent((int) $student->getKey());

            if (! $student instanceof Student) {
                throw new AuthorizationException;
            }

            $semester = Semester::query()->active()->first();

            if (! $semester instanceof Semester) {
                throw ValidationException::withMessages(['registration' => self::MESSAGE_NO_SEMESTER]);
            }

            $registration = $this->lockedRegistration($student, $semester);
            $approval_required = (bool) $this->settings->effective()['approval_required'];
            $target = $this->targetStatus($submitting, $approval_required);
            $assessment = $this->assess($student);

            if ($assessment['state'] === 'not_active') {
                throw ValidationException::withMessages(['student' => self::MESSAGE_NOT_ACTIVE]);
            }

            $items = $this->selectedItems($assessment, $course_ids);
            $resolved_ids = [];

            foreach ($items as $item) {
                $resolved_ids[] = $item['course_id'];
            }

            if ($registration instanceof CourseRegistration && $this->sameCourseIds($registration, $target, $resolved_ids)) {
                return $registration;
            }

            if (! $assessment['can_start']) {
                throw ValidationException::withMessages([
                    'registration' => $assessment['message'] ?? self::MESSAGE_NO_DEADLINE,
                ]);
            }

            if ($submitting) {
                $checked_total = 0;

                foreach ($items as $item) {
                    $checked_total += $item['credit_units'];
                }

                $problem = $this->unitMessage(
                    $checked_total,
                    $assessment['core_units'],
                    $assessment['min_units'],
                    $assessment['max_units'],
                );

                if ($problem !== null) {
                    throw ValidationException::withMessages(['total_units' => $problem]);
                }
            }

            if (! $registration instanceof CourseRegistration) {
                $registration = $this->newRegistration($student, $semester);
            }

            if ($this->sameCourseIds($registration, $target, $resolved_ids)) {
                return $registration;
            }

            if (! $this->transitionAllowed($registration->status, $target)) {
                throw ValidationException::withMessages(['registration' => self::MESSAGE_TRANSITION]);
            }

            $before = $registration->wasRecentlyCreated ? null : $this->snapshot($registration);
            $total_units = 0;

            foreach ($items as $item) {
                $total_units += $item['credit_units'];
            }

            $this->replaceItems($registration, $items);
            $registration->status = $target;
            $registration->total_units = $total_units;
            $registration->submitted_at = $submitting ? now() : $registration->submitted_at;
            $registration->decided_at = $target === RegistrationStatus::Approved ? now() : null;
            $registration->decided_by = null;
            $registration->rejection_reason = null;
            $registration->save();

            /** @var array<string, bool|int|string> $after */
            $after = $this->snapshot($registration);

            if ($submitting && ! $approval_required) {
                $after['auto_approved'] = true;
            }

            $this->audit->record(
                (int) $actor->id,
                $submitting ? self::ACTION_SUBMITTED : self::ACTION_SAVED,
                'course_registrations',
                (int) $registration->getKey(),
                $before,
                $after,
                $this->audit->ipFromRequest($request),
            );

            if ($submitting && $target === RegistrationStatus::Submitted) {
                $this->notifications->create($actor, NotificationType::RegistrationSubmitted->value, [
                    'title' => self::NOTICE_SUBMITTED_TITLE,
                    'message' => self::NOTICE_SUBMITTED_MESSAGE,
                    'link' => self::NOTICE_LINK,
                ]);
            }

            if ($target === RegistrationStatus::Approved) {
                $this->notifyApproved($actor, $registration, $semester);
            }

            return $registration;
        });

        return $registration;
    }

    /**
     * In-app confirmation and one email. The email template is the approved wording.
     *
     * A mail failure does not undo the approval. The warning names neither the
     * address nor a secret.
     */
    private function notifyApproved(User $actor, CourseRegistration $registration, Semester $semester): void
    {
        $this->notifications->create($actor, NotificationType::RegistrationConfirmed->value, [
            'title' => self::NOTICE_CONFIRMED_TITLE,
            'message' => self::NOTICE_CONFIRMED_MESSAGE,
            'link' => self::NOTICE_LINK,
        ]);

        $semester->loadMissing('academicSession');
        $email = $actor->email;

        if ($email === '') {
            Log::warning(self::WARNING_EMAIL);

            return;
        }

        try {
            $this->mail->send(
                EmailTemplate::RegistrationConfirmed->value,
                $email,
                [
                    'name' => (string) $actor->name,
                    'session_name' => (string) $semester->academicSession?->name,
                    'semester_name' => (string) $semester->name,
                    'total_units' => (int) $registration->total_units,
                ],
                (int) $actor->getKey(),
                'course_registrations',
                (int) $registration->getKey(),
            );
        } catch (Throwable) {
            Log::warning(self::WARNING_EMAIL);
        }
    }

    /**
     * Lock the student row. Callers lock this before the registration row.
     */
    private function lockStudent(int $student_id): ?Student
    {
        $student = Student::query()->whereKey($student_id)->lockForUpdate()->first();

        return $student instanceof Student ? $student : null;
    }

    /**
     * The row for this student and semester, locked, or null when it does not exist yet.
     *
     * The student row is already locked by the caller.
     */
    private function lockedRegistration(Student $student, Semester $semester): ?CourseRegistration
    {
        return CourseRegistration::query()
            ->where('student_id', $student->getKey())
            ->where('semester_id', $semester->getKey())
            ->lockForUpdate()
            ->first();
    }

    /**
     * Insert the row. The student lock is already held, so a second writer waits and finds this row.
     */
    private function newRegistration(Student $student, Semester $semester): CourseRegistration
    {
        $registration = new CourseRegistration;
        $registration->student_id = $student->getKey();
        $registration->semester_id = $semester->getKey();
        $registration->status = RegistrationStatus::Draft;
        $registration->total_units = 0;

        try {
            $registration->save();
        } catch (UniqueConstraintViolationException) {
            $existing = $this->lockedRegistration($student, $semester);

            if (! $existing instanceof CourseRegistration) {
                throw ValidationException::withMessages(['registration' => self::MESSAGE_TRANSITION]);
            }

            return $existing;
        }

        return $registration;
    }

    /**
     * @param  list<array{course_id: int, credit_units: int}>  $items
     */
    private function replaceItems(CourseRegistration $registration, array $items): void
    {
        CourseRegistrationItem::query()
            ->where('course_registration_id', $registration->getKey())
            ->delete();

        foreach ($items as $item) {
            $row = new CourseRegistrationItem;
            $row->course_registration_id = $registration->getKey();
            $row->course_id = $item['course_id'];
            $row->credit_units = $item['credit_units'];
            $row->save();
        }
    }

    /**
     * True when this row is already in the target status with these course ids.
     *
     * Credit units are not compared. A repeat does not copy a later catalogue change.
     *
     * @param  list<int>  $course_ids
     */
    private function sameCourseIds(CourseRegistration $registration, RegistrationStatus $target, array $course_ids): bool
    {
        if ($registration->status !== $target) {
            return false;
        }

        $stored = [];

        foreach ($registration->items()->get() as $item) {
            $stored[] = (int) $item->course_id;
        }

        $incoming = [];

        foreach ($course_ids as $course_id) {
            $incoming[] = (int) $course_id;
        }

        sort($stored);
        sort($incoming);

        return $stored === $incoming;
    }

    private function targetStatus(bool $submitting, bool $approval_required): RegistrationStatus
    {
        if (! $submitting) {
            return RegistrationStatus::Draft;
        }

        if ($approval_required) {
            return RegistrationStatus::Submitted;
        }

        return RegistrationStatus::Approved;
    }

    /**
     * Draft and rejected rows can be saved or submitted. Submitted and approved cannot.
     */
    private function transitionAllowed(RegistrationStatus $from, RegistrationStatus $to): bool
    {
        if ($from === RegistrationStatus::Draft || $from === RegistrationStatus::Rejected) {
            return $to === RegistrationStatus::Draft
                || $to === RegistrationStatus::Submitted
                || $to === RegistrationStatus::Approved;
        }

        return false;
    }

    /**
     * Ids, status, and unit total. Reasons and names stay off the audit row.
     *
     * @return array{student_id: int, semester_id: int, status: string, total_units: int}
     */
    private function snapshot(CourseRegistration $registration): array
    {
        return [
            'student_id' => (int) $registration->student_id,
            'semester_id' => (int) $registration->semester_id,
            'status' => $registration->status->value,
            'total_units' => (int) $registration->total_units,
        ];
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
