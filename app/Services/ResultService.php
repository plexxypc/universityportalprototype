<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailTemplate;
use App\Enums\NotificationType;
use App\Enums\RegistrationStatus;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AssessmentComponent;
use App\Models\CourseAssignment;
use App\Models\CourseRegistration;
use App\Models\CourseRegistrationItem;
use App\Models\GradingScheme;
use App\Models\Result;
use App\Models\ResultScore;
use App\Models\Semester;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use App\Support\Deadlock;
use App\Support\Grading\Hundredths;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Result scores and the submit, approve, return, and publish steps.
 *
 * Totals, letters, and points are recomputed here. The client cannot send them.
 * Locks are the active scheme, then registration rows, then registration items,
 * then result rows, then score rows, each group in id order.
 */
class ResultService
{
    public const string MESSAGE_SCHEME = 'There is no grading scheme.';

    public const string MESSAGE_CLIENT_TOTAL = 'The total is calculated by the portal.';

    public const string MESSAGE_SCORE = 'Enter a score from 0 to the component maximum, with at most two decimal places.';

    public const string MESSAGE_COMPONENT = 'That component is not part of this result\'s scheme.';

    public const string MESSAGE_STUDENT = 'That student does not have an approved registration for this course.';

    public const string MESSAGE_STATUS = 'These results can no longer be edited.';

    public const string MESSAGE_VERSION = 'Someone else saved this row. Reload it and try again.';

    public const string MESSAGE_MISSING = 'Every registered student needs a complete result.';

    public const string MESSAGE_DISAGREE = 'The stored total does not match the scores.';

    public const string MESSAGE_TRANSITION = 'This result cannot make that change.';

    public const string MESSAGE_ENTERER = 'You entered this result, so you cannot approve it.';

    public const string MESSAGE_REASON = 'Enter a reason of 1 to 500 characters.';

    public const string MESSAGE_PUBLISH = 'Only approved results can be published.';

    public const string ACTION_ENTERED = 'result.entered';

    public const string ACTION_CHANGED = 'result.changed';

    public const string ACTION_SUBMITTED = 'result.submitted';

    public const string ACTION_RETURNED = 'result.returned';

    public const string ACTION_APPROVED = 'result.approved';

    public const string ACTION_PUBLISHED = 'result.published';

    public const string NOTICE_PUBLISHED_TITLE = 'Result published';

    public const string NOTICE_RETURNED_TITLE = 'Result returned';

    public const string NOTICE_LINK = '/student/results';

    private const string WARNING_NOTICE = 'A result notice could not be sent.';

    private const string RETURNED_PREFIX = 'Your results were returned. ';

    /**
     * Set by a test to throw one simulated deadlock. Production leaves it null.
     */
    public ?\Closure $deadlockProbe = null;

    public function __construct(
        private readonly GradingService $grading,
        private readonly Audit $audit,
        private readonly NotificationService $notifications,
        private readonly MailService $mail,
    ) {}

    /**
     * Save draft scores for the assigned course and semester.
     *
     * A blank score is left unentered. Zero is stored. An unchanged row writes
     * no audit row. The semester does not have to be the active semester.
     *
     * @param  list<array{student_id: int, scores: array<int, string|null>, version: ?string}>  $rows
     * @return list<array{result_id: int, student_id: int, updated_at: string}>
     */
    public function saveDraft(User $actor, int $course_id, int $semester_id, array $rows, ?Request $request = null): array
    {
        $this->assertCanEnter($actor, $course_id, $semester_id);
        $parsed = $this->parsedRows($rows);

        return Deadlock::attempt(function () use ($actor, $course_id, $semester_id, $parsed, $request): array {
            return DB::transaction(function () use ($actor, $course_id, $semester_id, $parsed, $request): array {
                $this->probe();
                $active = $this->lockActiveScheme();
                $roster = $this->lockRoster($course_id, $semester_id);
                $results = $this->lockResults($course_id, $semester_id);
                $saved = [];

                foreach ($parsed as $row) {
                    $saved[] = $this->saveOne(
                        $actor,
                        $course_id,
                        $semester_id,
                        $row,
                        $roster,
                        $results,
                        $active,
                        $request,
                    );
                }

                return $saved;
            });
        });
    }

    /**
     * Whether this account may enter scores for the course and semester.
     */
    private function assertCanEnter(User $actor, int $course_id, int $semester_id): void
    {
        if ($actor->status !== UserStatus::Active || ! Gate::forUser($actor)->allows('results.enter')) {
            throw new AuthorizationException;
        }

        if ($actor->hasRole(Role::SuperAdmin)) {
            return;
        }

        $assigned = CourseAssignment::query()
            ->join('staff', 'staff.id', '=', 'course_assignments.staff_id')
            ->where('course_assignments.course_id', $course_id)
            ->where('course_assignments.semester_id', $semester_id)
            ->where('staff.user_id', $actor->id)
            ->exists();

        if (! $assigned) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  list<array{student_id: int, scores: array<int, string|null>, version: ?string}>  $rows
     * @return list<array{student_id: int, scores: array<int, ?int>, version: ?string}>
     */
    private function parsedRows(array $rows): array
    {
        $parsed = [];

        foreach ($rows as $row) {
            foreach (['total', 'grade', 'points'] as $banned) {
                if (array_key_exists($banned, $row)) {
                    throw ValidationException::withMessages(['total' => self::MESSAGE_CLIENT_TOTAL]);
                }
            }

            $scores = [];

            foreach ($row['scores'] as $component_id => $score) {
                $scores[(int) $component_id] = $this->scoreHundredths($score);
            }

            $parsed[] = [
                'student_id' => (int) $row['student_id'],
                'scores' => $scores,
                'version' => $row['version'],
            ];
        }

        return $parsed;
    }

    /**
     * Hundredths, or null when the cell was left blank.
     */
    private function scoreHundredths(?string $score): ?int
    {
        if ($score === null || trim($score) === '') {
            return null;
        }

        try {
            return Hundredths::fromDecimal(trim($score));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['score' => self::MESSAGE_SCORE]);
        }
    }

    /**
     * Lock the active scheme when one exists.
     */
    private function lockActiveScheme(): ?GradingScheme
    {
        $scheme = GradingScheme::query()->active()->orderBy('id')->lockForUpdate()->first();

        return $scheme instanceof GradingScheme ? $scheme : null;
    }

    /**
     * Approved registration items for this course and semester, locked in id order.
     *
     * Registration rows are locked first, then the items.
     *
     * @return array<int, int> student id => credit units
     */
    private function lockRoster(int $course_id, int $semester_id): array
    {
        $registrations = CourseRegistration::query()
            ->where('semester_id', $semester_id)
            ->where('status', RegistrationStatus::Approved->value)
            ->whereIn('id', function ($query) use ($course_id): void {
                $query->select('course_registration_id')
                    ->from('course_registration_items')
                    ->where('course_id', $course_id);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'student_id']);

        $registration_ids = [];

        foreach ($registrations as $registration) {
            $registration_ids[] = (int) $registration->getKey();
        }

        if ($registration_ids === []) {
            return [];
        }

        $items = CourseRegistrationItem::query()
            ->where('course_id', $course_id)
            ->whereIn('course_registration_id', $registration_ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $students = [];

        foreach ($registrations as $registration) {
            $students[(int) $registration->getKey()] = (int) $registration->student_id;
        }

        $roster = [];

        foreach ($items as $item) {
            $student_id = $students[(int) $item->course_registration_id] ?? null;

            if ($student_id !== null) {
                $roster[$student_id] = (int) $item->credit_units;
            }
        }

        return $roster;
    }

    /**
     * Result rows for this course and semester, locked in id order.
     *
     * @return Collection<int, Result>
     */
    private function lockResults(int $course_id, int $semester_id): Collection
    {
        return Result::query()
            ->where('course_id', $course_id)
            ->where('semester_id', $semester_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array{student_id: int, scores: array<int, ?int>, version: ?string}  $row
     * @param  array<int, int>  $roster
     * @param  Collection<int, Result>  $results
     * @return array{result_id: int, student_id: int, updated_at: string}
     */
    private function saveOne(
        User $actor,
        int $course_id,
        int $semester_id,
        array $row,
        array $roster,
        Collection $results,
        ?GradingScheme $active,
        ?Request $request,
    ): array {
        if (! array_key_exists($row['student_id'], $roster)) {
            throw ValidationException::withMessages(['student_id' => self::MESSAGE_STUDENT]);
        }

        $result = $results->firstWhere('student_id', $row['student_id']);

        if (! $result instanceof Result) {
            if ($row['version'] !== null) {
                throw ValidationException::withMessages(['version' => self::MESSAGE_VERSION]);
            }

            if (! $active instanceof GradingScheme) {
                throw ValidationException::withMessages(['grading_scheme_id' => self::MESSAGE_SCHEME]);
            }

            $result = new Result;
            $result->student_id = $row['student_id'];
            $result->course_id = $course_id;
            $result->semester_id = $semester_id;
            $result->grading_scheme_id = $active->id;
            $result->scheme_version = (int) $active->version;
            $result->status = ResultStatus::Draft;
            $result->entered_by = $actor->id;
            $result->approved_by = null;
            $result->published_at = null;

            try {
                $result->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['version' => self::MESSAGE_VERSION]);
            }

            $results->push($result);
            $before = null;
            $action = self::ACTION_ENTERED;
        } else {
            $this->assertVersion($result, $row['version']);

            if ($result->status !== ResultStatus::Draft) {
                throw ValidationException::withMessages(['status' => self::MESSAGE_STATUS]);
            }

            $before = $this->snapshot($result);
            $action = self::ACTION_CHANGED;
        }

        $scheme = $this->schemeFor($result);
        $this->assertSchemeVersion($result, $scheme);
        $scores = $this->checkedScores($scheme, $row['scores']);
        $this->writeScores($result, $scores);
        $computed = $this->computed($scheme, $scores);
        $result->total = $computed['total'];
        $result->grade = $computed['grade'];
        $result->points = $computed['points'];
        $after = $this->snapshot($result);

        if ($before !== $after) {
            $result->save();
            $this->audit->record(
                (int) $actor->id,
                $action,
                'results',
                (int) $result->getKey(),
                $before,
                $after,
                $this->audit->ipFromRequest($request ?? Request::create('/')),
            );
        }

        $result->refresh();

        return [
            'result_id' => (int) $result->getKey(),
            'student_id' => (int) $result->student_id,
            'updated_at' => $result->updated_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }

    /**
     * The token is the row's updated_at, to the second.
     */
    private function assertVersion(Result $result, ?string $version): void
    {
        $stored = $result->updated_at?->format('Y-m-d H:i:s');

        if ($version === null || $stored === null || $version !== $stored) {
            throw ValidationException::withMessages(['version' => self::MESSAGE_VERSION]);
        }
    }

    /**
     * The scheme row this result already points at.
     */
    private function schemeFor(Result $result): GradingScheme
    {
        $scheme = GradingScheme::query()->whereKey($result->grading_scheme_id)->first();

        if (! $scheme instanceof GradingScheme) {
            throw ValidationException::withMessages(['grading_scheme_id' => self::MESSAGE_SCHEME]);
        }

        return $scheme;
    }

    /**
     * scheme_version is the linked row's version.
     */
    private function assertSchemeVersion(Result $result, GradingScheme $scheme): void
    {
        if ((int) $result->scheme_version !== (int) $scheme->version) {
            throw ValidationException::withMessages(['scheme_version' => self::MESSAGE_SCHEME]);
        }
    }

    /**
     * Drop blanks and refuse a foreign component or a score above the maximum.
     *
     * @param  array<int, ?int>  $scores
     * @return array<int, int>
     */
    private function checkedScores(GradingScheme $scheme, array $scores): array
    {
        $components = $scheme->assessmentComponents()->get()->keyBy('id');
        $kept = [];

        foreach ($scores as $component_id => $hundredths) {
            if ($hundredths === null) {
                continue;
            }

            $component = $components->get($component_id);

            if (! $component instanceof AssessmentComponent) {
                throw ValidationException::withMessages(['component_id' => self::MESSAGE_COMPONENT]);
            }

            $maximum = Hundredths::fromDecimal((string) $component->max_score);

            if ($hundredths < 0 || $hundredths > $maximum) {
                throw ValidationException::withMessages(['score' => self::MESSAGE_SCORE]);
            }

            $kept[(int) $component_id] = $hundredths;
        }

        return $kept;
    }

    /**
     * Replace this result's scores. A missing component stays unentered.
     *
     * @param  array<int, int>  $scores
     */
    private function writeScores(Result $result, array $scores): void
    {
        $existing = $result->scores()->get()->keyBy('component_id');

        foreach ($scores as $component_id => $hundredths) {
            $decimal = Hundredths::toDecimal($hundredths);
            $row = $existing->get($component_id);

            if ($row instanceof ResultScore) {
                if ((string) $row->score !== $decimal) {
                    $row->score = $decimal;
                    $row->save();
                }

                $existing->forget($component_id);

                continue;
            }

            ResultScore::query()->create([
                'result_id' => $result->getKey(),
                'component_id' => $component_id,
                'score' => $decimal,
            ]);
        }

        foreach ($existing as $row) {
            $row->delete();
        }
    }

    /**
     * Total, letter, and points, or nulls when a component has not been entered.
     *
     * @param  array<int, int>  $scores
     * @return array{total: ?string, grade: ?string, points: ?string}
     */
    private function computed(GradingScheme $scheme, array $scores): array
    {
        $components = $scheme->assessmentComponents()->orderBy('sort')->orderBy('id')->get();
        $empty = ['total' => null, 'grade' => null, 'points' => null];

        if ($components->isEmpty()) {
            return $empty;
        }

        $values = [];

        foreach ($components as $component) {
            if (! array_key_exists((int) $component->getKey(), $scores)) {
                return $empty;
            }

            $values[] = $scores[(int) $component->getKey()];
        }

        $total = $this->grading->total($values);
        $grade = $this->grading->grade(
            $total,
            $this->bands($scheme),
            Hundredths::fromDecimal((string) $scheme->pass_mark),
        );

        return [
            'total' => Hundredths::toDecimal($total),
            'grade' => $grade['letter'],
            'points' => Hundredths::toDecimal($grade['points_hundredths']),
        ];
    }

    /**
     * @return list<array{min_hundredths: int, max_hundredths: int, letter: string, points_hundredths: int, remark: string}>
     */
    private function bands(GradingScheme $scheme): array
    {
        $bands = [];

        foreach ($scheme->gradeBands()->orderBy('min_score')->get() as $band) {
            $bands[] = [
                'min_hundredths' => Hundredths::fromDecimal((string) $band->min_score),
                'max_hundredths' => Hundredths::fromDecimal((string) $band->max_score),
                'letter' => (string) $band->letter,
                'points_hundredths' => Hundredths::fromDecimal((string) $band->points),
                'remark' => (string) $band->remark,
            ];
        }

        return $bands;
    }

    /**
     * Submit every draft for this course. A row that is already submitted stays.
     *
     * An approved or published row on the course blocks the whole submit.
     * The return reason is cleared. A stored total that disagrees is refused
     * and writes no audit row.
     */
    public function submit(User $actor, int $course_id, int $semester_id, ?Request $request = null): void
    {
        $this->assertCanEnter($actor, $course_id, $semester_id);
        $this->courseTransaction($course_id, $semester_id, function (array $roster, Collection $results) use ($actor, $request): void {
            $rows = $this->resultsByStudent($results, $roster);
            $this->requireRows($roster, $rows);

            foreach ($rows as $result) {
                if ($result->status === ResultStatus::Approved || $result->status === ResultStatus::Published) {
                    throw ValidationException::withMessages(['status' => self::MESSAGE_TRANSITION]);
                }
            }

            foreach ($rows as $result) {
                $this->agreed($result);

                if ($result->status !== ResultStatus::Draft) {
                    continue;
                }

                $before = $this->snapshot($result);
                $result->status = ResultStatus::Submitted;
                $result->return_reason = null;
                $result->save();
                $this->record($actor, self::ACTION_SUBMITTED, $result, $before, $request);
            }
        });
    }

    /**
     * Approve every submitted row. The person who entered a row cannot approve it.
     *
     * A department officer must cover every student's department. Anyone else
     * with the approve permission is unscoped. A draft or published row blocks
     * the course.
     */
    public function approve(User $actor, int $course_id, int $semester_id, ?Request $request = null): void
    {
        $this->assertCanApprove($actor);
        $this->courseTransaction($course_id, $semester_id, function (array $roster, Collection $results) use ($actor, $request): void {
            $this->assertDepartment($actor, $roster);
            $rows = $this->resultsByStudent($results, $roster);
            $this->requireRows($roster, $rows);
            $this->assertNotEnterer($actor, $rows);

            foreach ($rows as $result) {
                if ($result->status === ResultStatus::Draft || $result->status === ResultStatus::Published) {
                    throw ValidationException::withMessages(['status' => self::MESSAGE_TRANSITION]);
                }
            }

            foreach ($rows as $result) {
                $this->agreed($result);

                if ($result->status !== ResultStatus::Submitted) {
                    continue;
                }

                $before = $this->snapshot($result);
                $result->status = ResultStatus::Approved;
                $result->approved_by = $actor->id;
                $result->save();
                $this->record($actor, self::ACTION_APPROVED, $result, $before, $request);
            }
        });
    }

    /**
     * Send submitted and approved rows back to draft with one reason.
     *
     * A published row blocks the course. Each changed row is audited. One
     * notice goes to each distinct enterer after the commit.
     */
    public function returnToDraft(User $actor, int $course_id, int $semester_id, string $reason, ?Request $request = null): void
    {
        $this->assertCanApprove($actor);
        $reason = $this->cleanReason($reason);
        $entered = $this->courseTransaction($course_id, $semester_id, function (array $roster, Collection $results) use ($actor, $reason, $request): array {
            $this->assertDepartment($actor, $roster);
            $rows = $this->resultsByStudent($results, $roster);
            $recipients = [];

            foreach ($rows as $result) {
                if ($result->status === ResultStatus::Published) {
                    throw ValidationException::withMessages(['status' => self::MESSAGE_TRANSITION]);
                }
            }

            foreach ($rows as $result) {
                if ($result->status !== ResultStatus::Submitted && $result->status !== ResultStatus::Approved) {
                    continue;
                }

                $before = $this->snapshot($result);
                $result->status = ResultStatus::Draft;
                $result->approved_by = null;
                $result->return_reason = $reason;
                $result->save();
                $this->record($actor, self::ACTION_RETURNED, $result, $before, $request);
                $recipients[(int) $result->entered_by] = true;
            }

            return array_keys($recipients);
        });
        $this->notifyReturned($entered, $reason);
    }

    /**
     * Publish one course, then tell each newly published student once.
     *
     * @param  list<int>  $course_ids
     */
    public function publish(User $actor, int $semester_id, array $course_ids, ?Request $request = null): void
    {
        $this->assertCanPublish($actor);
        $ids = [];

        foreach ($course_ids as $course_id) {
            $ids[] = (int) $course_id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);
        $notified = [];
        $failure = null;

        try {
            foreach ($ids as $course_id) {
                try {
                    $students = $this->publishOne($actor, $course_id, $semester_id, $request);

                    foreach ($students as $student_id) {
                        $notified[$student_id] = true;
                    }
                } catch (ValidationException $exception) {
                    $failure ??= $exception;
                }
            }
        } finally {
            $this->notifyPublished(array_keys($notified), $semester_id);
        }

        if ($failure instanceof ValidationException) {
            throw $failure;
        }
    }

    /**
     * Publish one course inside its own transaction. Already published rows stay.
     *
     * @return list<int>
     */
    private function publishOne(User $actor, int $course_id, int $semester_id, ?Request $request): array
    {
        return $this->courseTransaction($course_id, $semester_id, function (array $roster, Collection $results) use ($actor, $request): array {
            $rows = $this->resultsByStudent($results, $roster);
            $this->requireRows($roster, $rows);
            $published = [];

            foreach ($rows as $result) {
                if ($result->status === ResultStatus::Draft || $result->status === ResultStatus::Submitted) {
                    throw ValidationException::withMessages(['status' => self::MESSAGE_PUBLISH]);
                }
            }

            foreach ($rows as $result) {
                $this->agreed($result);

                if ($result->status === ResultStatus::Published) {
                    continue;
                }

                $before = $this->snapshot($result);
                $result->status = ResultStatus::Published;
                $result->published_at = now();
                $result->save();
                $this->record($actor, self::ACTION_PUBLISHED, $result, $before, $request);
                $published[] = (int) $result->student_id;
            }

            return $published;
        });
    }

    /**
     * Lock the course, then run the change. A deadlock is tried again.
     *
     * @template T
     *
     * @param  callable(array<int, int>, Collection<int, Result>): T  $callback
     * @return T
     */
    private function courseTransaction(int $course_id, int $semester_id, callable $callback): mixed
    {
        return Deadlock::attempt(function () use ($course_id, $semester_id, $callback): mixed {
            return DB::transaction(function () use ($course_id, $semester_id, $callback): mixed {
                $this->probe();
                $this->lockActiveScheme();
                $roster = $this->lockRoster($course_id, $semester_id);
                $results = $this->lockResults($course_id, $semester_id);
                $this->lockScores($results);

                return $callback($roster, $results);
            });
        });
    }

    /**
     * Score rows for these results, locked in id order after the result rows.
     *
     * @param  Collection<int, Result>  $results
     */
    private function lockScores(Collection $results): void
    {
        $ids = [];

        foreach ($results as $result) {
            $ids[] = (int) $result->getKey();
        }

        if ($ids === []) {
            return;
        }

        ResultScore::query()->whereIn('result_id', $ids)->orderBy('id')->lockForUpdate()->get();
        $results->load('scores');
    }

    /**
     * Whether this account may approve or return results.
     */
    private function assertCanApprove(User $actor): void
    {
        if ($actor->status !== UserStatus::Active || ! Gate::forUser($actor)->allows('results.approve')) {
            throw new AuthorizationException;
        }
    }

    /**
     * Whether this account may publish results.
     */
    private function assertCanPublish(User $actor): void
    {
        if ($actor->status !== UserStatus::Active || ! Gate::forUser($actor)->allows('results.publish')) {
            throw new AuthorizationException;
        }
    }

    /**
     * A department officer must cover every student. Other approvers are unscoped.
     *
     * @param  array<int, int>  $roster
     */
    private function assertDepartment(User $actor, array $roster): void
    {
        if ($actor->hasRole(Role::SuperAdmin) || $actor->hasRole(Role::ExamOfficer)) {
            return;
        }

        $ids = array_keys($roster);
        $students = Student::query()->with('programme')->whereIn('id', $ids)->get();

        if ($students->count() !== count($ids)) {
            throw new NotFoundHttpException;
        }

        $allowed = $actor->departmentIds();

        foreach ($students as $student) {
            $department_id = (int) $student->programme->department_id;

            if (! in_array($department_id, $allowed, true)) {
                throw new NotFoundHttpException;
            }
        }
    }

    /**
     * The enterer cannot approve any row of this course.
     *
     * @param  array<int, Result>  $rows
     */
    private function assertNotEnterer(User $actor, array $rows): void
    {
        foreach ($rows as $result) {
            if ((int) $result->entered_by === (int) $actor->id) {
                throw ValidationException::withMessages(['approved_by' => self::MESSAGE_ENTERER]);
            }
        }
    }

    /**
     * @param  Collection<int, Result>  $results
     * @param  array<int, int>  $roster
     * @return array<int, Result>
     */
    private function resultsByStudent(Collection $results, array $roster): array
    {
        $rows = [];

        foreach ($results as $result) {
            $student_id = (int) $result->student_id;

            if (! array_key_exists($student_id, $roster)) {
                throw ValidationException::withMessages(['student_id' => self::MESSAGE_STUDENT]);
            }

            $rows[$student_id] = $result;
        }

        return $rows;
    }

    /**
     * @param  array<int, int>  $roster
     * @param  array<int, Result>  $rows
     */
    private function requireRows(array $roster, array $rows): void
    {
        $missing = [];

        foreach (array_keys($roster) as $student_id) {
            if (! array_key_exists($student_id, $rows)) {
                $missing[] = $student_id;
            }
        }

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'student_id' => self::MESSAGE_MISSING.' '.implode(', ', $missing),
            ]);
        }
    }

    /**
     * The stored total, letter, and points match a complete recomputation.
     */
    private function agreed(Result $result): void
    {
        $scheme = $this->schemeFor($result);
        $this->assertSchemeVersion($result, $scheme);
        $scores = [];

        foreach ($result->scores as $score) {
            $scores[(int) $score->component_id] = Hundredths::fromDecimal((string) $score->score);
        }

        $computed = $this->computed($scheme, $this->checkedScores($scheme, $scores));

        if ($computed['total'] === null || $computed['grade'] === null || $computed['points'] === null) {
            throw ValidationException::withMessages([
                'student_id' => self::MESSAGE_MISSING.' '.(int) $result->student_id,
            ]);
        }

        $stored_total = $result->total === null ? null : (string) $result->total;
        $stored_points = $result->points === null ? null : (string) $result->points;

        if ($stored_total !== $computed['total'] || $result->grade !== $computed['grade'] || $stored_points !== $computed['points']) {
            throw ValidationException::withMessages(['total' => self::MESSAGE_DISAGREE]);
        }
    }

    /**
     * One line, from 1 to 500 characters.
     */
    private function cleanReason(string $reason): string
    {
        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < 1 || $length > 500 || preg_match('/[\r\n]/', $reason) === 1) {
            throw ValidationException::withMessages(['reason' => self::MESSAGE_REASON]);
        }

        return $reason;
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    private function record(User $actor, string $action, Result $result, ?array $before, ?Request $request): void
    {
        $this->audit->record(
            (int) $actor->id,
            $action,
            'results',
            (int) $result->getKey(),
            $before,
            $this->snapshot($result),
            $this->audit->ipFromRequest($request ?? Request::create('/')),
        );
    }

    /**
     * @param  list<int>  $user_ids
     */
    private function notifyReturned(array $user_ids, string $reason): void
    {
        if ($user_ids === []) {
            return;
        }

        $message = $this->returnedMessage($reason);
        $users = User::query()->whereIn('id', $user_ids)->orderBy('id')->get();

        foreach ($users as $user) {
            try {
                $this->notifications->create($user, NotificationType::ResultReturned->value, [
                    'title' => self::NOTICE_RETURNED_TITLE,
                    'message' => $message,
                ]);
            } catch (Throwable) {
                Log::warning(self::WARNING_NOTICE);
            }
        }
    }

    /**
     * The notice keeps the reason when it fits, and cuts it when it does not.
     */
    private function returnedMessage(string $reason): string
    {
        $room = 240 - mb_strlen(self::RETURNED_PREFIX);

        if (mb_strlen($reason) <= $room) {
            return self::RETURNED_PREFIX.$reason;
        }

        return self::RETURNED_PREFIX.mb_substr($reason, 0, $room);
    }

    /**
     * One notice and one email per student. A failure leaves the publication.
     *
     * @param  list<int>  $student_ids
     */
    private function notifyPublished(array $student_ids, int $semester_id): void
    {
        if ($student_ids === []) {
            return;
        }

        $semester = Semester::query()->with('academicSession')->find($semester_id);

        if (! $semester instanceof Semester) {
            Log::warning(self::WARNING_NOTICE);

            return;
        }

        $students = Student::query()->with('user')->whereIn('id', $student_ids)->orderBy('id')->get();

        foreach ($students as $student) {
            $user = $student->user;

            if (! $user instanceof User) {
                Log::warning(self::WARNING_NOTICE);

                continue;
            }

            try {
                $this->notifications->create($user, NotificationType::ResultPublished->value, [
                    'title' => self::NOTICE_PUBLISHED_TITLE,
                    'message' => 'Your results for '.$semester->name.' have been published.',
                    'link' => self::NOTICE_LINK,
                ]);
            } catch (Throwable) {
                Log::warning(self::WARNING_NOTICE);
            }

            $email = (string) $user->email;

            if ($email === '') {
                Log::warning(self::WARNING_NOTICE);

                continue;
            }

            try {
                $this->mail->send(
                    EmailTemplate::ResultPublished->value,
                    $email,
                    [
                        'name' => (string) $user->name,
                        'session_name' => (string) $semester->academicSession?->name,
                        'semester_name' => (string) $semester->name,
                        'change' => 'published',
                    ],
                    (int) $user->getKey(),
                    'results',
                    $semester_id,
                );
            } catch (Throwable) {
                Log::warning(self::WARNING_NOTICE);
            }
        }
    }

    /**
     * Academic values only. Names and matric numbers stay out.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Result $result): array
    {
        $result->unsetRelation('scores');
        $scores = [];

        foreach ($result->scores()->orderBy('component_id')->get() as $score) {
            $scores[] = [
                'component_id' => (int) $score->component_id,
                'score' => (string) $score->score,
            ];
        }

        return [
            'student_id' => (int) $result->student_id,
            'course_id' => (int) $result->course_id,
            'semester_id' => (int) $result->semester_id,
            'grading_scheme_id' => (int) $result->grading_scheme_id,
            'scheme_version' => (int) $result->scheme_version,
            'status' => $result->status->value,
            'total' => $result->total === null ? null : (string) $result->total,
            'grade' => $result->grade,
            'points' => $result->points === null ? null : (string) $result->points,
            'return_reason' => $result->return_reason,
            'scores' => $scores,
        ];
    }

    /**
     * Throw the test deadlock, once per attempt.
     */
    private function probe(): void
    {
        if ($this->deadlockProbe !== null) {
            ($this->deadlockProbe)();
        }
    }
}
