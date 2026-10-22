<?php

declare(strict_types=1);

namespace App\Services;

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
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Draft result scores for one course and semester.
 *
 * Totals, letters, and points are recomputed here. The client cannot send them.
 * Locks are the active scheme, then registration rows, then registration items,
 * then result rows, each group in id order.
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

    public const string ACTION_ENTERED = 'result.entered';

    public const string ACTION_CHANGED = 'result.changed';

    /**
     * Set by a test to throw one simulated deadlock. Production leaves it null.
     */
    public ?\Closure $deadlockProbe = null;

    public function __construct(
        private readonly GradingService $grading,
        private readonly Audit $audit,
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
