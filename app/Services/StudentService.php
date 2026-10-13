<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailTemplate;
use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\AcademicSession;
use App\Models\Counter;
use App\Models\EmailOutbox;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Policies\StudentPolicy;
use App\Support\Audit;
use App\Support\MatricPattern;
use App\Support\NigerianStates;
use App\Support\PersonalAudit;
use App\Support\Phone;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Creates and updates student records on behalf of staff.
 *
 * The user and the student are written in one transaction. Matric numbers
 * come from counters. Credentials are issued later by CredentialService.
 */
class StudentService
{
    public const string ACTION_CREATED = 'students.created';

    public const string ACTION_UPDATED = 'students.updated';

    public const string ACTION_STATUS_CHANGED = 'auth.status_changed';

    public const string ACTION_PROMOTED = 'students.promoted';

    public const int PROMOTION_CAP = 200;

    public const string MESSAGE_PROMOTION_CAP = 'Choose at most 200 students.';

    public const string MESSAGE_PROMOTION_CHANGED = 'The list changed. Review the new preview before promoting.';

    public const string MESSAGE_PROMOTION_INACTIVE = 'Only an active student can be promoted.';

    public const string MESSAGE_PROMOTION_LEVEL = 'This student is already at the highest level for the programme.';

    public const string MESSAGE_FIRST_NAME = 'Enter a first name.';

    public const string MESSAGE_LAST_NAME = 'Enter a last name.';

    public const string MESSAGE_NAME_LENGTH = 'Enter a shorter name.';

    public const string MESSAGE_EMAIL = 'Enter a valid email address.';

    public const string MESSAGE_EMAIL_TAKEN = 'An account with this email already exists.';

    public const string MESSAGE_PROGRAMME = 'Choose a programme.';

    public const string MESSAGE_LEVEL = 'This level is not valid for a %d-year programme.';

    public const string MESSAGE_LEVEL_NUMBER = 'Choose a level.';

    public const string MESSAGE_SESSION = 'Choose an entry session, or set a current academic session first.';

    public const string MESSAGE_SESSION_INVALID = 'Choose an academic session.';

    public const string MESSAGE_MATRIC_LENGTH = 'The matric number is longer than 32 characters. Shorten the department code or the matric pattern.';

    public const string MESSAGE_GENDER = 'Choose a gender.';

    public const string MESSAGE_DATE = 'Enter a valid date of birth.';

    public const string MESSAGE_STATE = 'Choose a state of origin.';

    public const string MESSAGE_ADDRESS = 'Enter an address of at most 1000 characters.';

    public const string MESSAGE_MISSING = 'That student was not found.';

    public const string MESSAGE_REASON = 'Enter a reason for this status change.';

    public const string MESSAGE_STATUS = 'Choose a student status.';

    public const string MESSAGE_STATUS_SAME = 'Choose a different status.';

    /**
     * Personal fields whose values stay out of the audit row.
     *
     * @var list<string>
     */
    private const array PERSONAL_FIELDS = [
        'first_name',
        'last_name',
        'other_names',
        'gender',
        'phone',
        'date_of_birth',
        'state_of_origin',
        'address',
    ];

    /**
     * Fields whose old and new values may be stored.
     *
     * @var list<string>
     */
    private const array VALUE_FIELDS = [
        'email',
        'status',
        'level',
        'programme_id',
        'entry_session_id',
        'matric_no',
    ];

    public function __construct(
        private readonly Audit $audit,
        private readonly AuthService $auth,
        private readonly CredentialService $credentials,
    ) {}

    /**
     * Create the user and the student together.
     *
     * A blank entry session uses the current academic session. The matric
     * number is allocated inside this transaction and is not returned as a
     * password or a credential.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(User $actor, array $input, Request $request): CreatedStudent
    {
        $this->authoriseCreate($actor);

        $created = DB::transaction(function () use ($actor, $input, $request): CreatedStudent {
            $this->authoriseCreate($actor);
            $parsed = $this->parsed($input, true);
            $session = $this->entrySession($parsed['entry_session_id']);
            $programme = $this->programme($parsed['programme_id']);
            $this->assertLevel($programme, $parsed['level']);
            $this->assertEmailAvailable($parsed['email'], null);

            $user = $this->newUser($parsed);
            $matric = $this->allocateMatric($programme, $session);
            $student = $this->newStudent($user, $programme, $session, $parsed, $matric);
            $delivery = $this->credentials->issue($actor, $user, $matric, $request);
            $audit = $this->creationAudit($parsed, $programme, $session, $matric);

            $this->audit->record(
                $actor->id,
                self::ACTION_CREATED,
                'students',
                (int) $student->getKey(),
                $audit['before'],
                $audit['after'],
                $this->audit->ipFromRequest($request),
            );

            return new CreatedStudent($student->load(['user', 'programme.department']), $delivery);
        });

        $row = EmailOutbox::query()
            ->where('user_id', $created->student->user_id)
            ->where('template', EmailTemplate::Credentials->value)
            ->latest('id')
            ->first();

        if (! $row instanceof EmailOutbox) {
            return $created;
        }

        return new CreatedStudent($created->student, $this->credentials->outcome($row));
    }

    /**
     * Update profile fields. The matric number stays as issued.
     *
     * Status is ignored here. changeStatus is the only status write.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $actor, int $student_id, array $input, Request $request): Student
    {
        return DB::transaction(function () use ($actor, $student_id, $input, $request): Student {
            $student = $this->lockedStudent($actor, $student_id);
            $student->load('user');
            $user = $student->user;

            if (! $user instanceof User) {
                throw ValidationException::withMessages(['student' => self::MESSAGE_MISSING]);
            }

            $locked_user = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $locked_user instanceof User) {
                throw ValidationException::withMessages(['student' => self::MESSAGE_MISSING]);
            }

            $parsed = $this->parsed($input, false, $student);
            $session = $this->entrySession($parsed['entry_session_id']);
            $programme = $this->programme($parsed['programme_id']);
            $this->assertLevel($programme, $parsed['level']);
            $this->assertEmailAvailable($parsed['email'], (int) $locked_user->id);

            $before = $this->fieldMap($student, $locked_user);
            $this->fillUser($locked_user, $parsed);
            $this->fillStudent($student, $programme, $session, $parsed);
            $locked_user->save();
            $student->save();
            $after = $this->fieldMap($student, $locked_user);
            $diff = PersonalAudit::diff($before, $after, self::PERSONAL_FIELDS, self::VALUE_FIELDS);

            $this->audit->record(
                $actor->id,
                self::ACTION_UPDATED,
                'students',
                (int) $student->getKey(),
                $diff['before'],
                $diff['after'],
                $this->audit->ipFromRequest($request),
            );

            return $student->load(['user', 'programme.department']);
        });
    }

    /**
     * Change the student status, the user status, and the sessions together.
     *
     * The reason is stored on auth.status_changed. Personal values are not.
     */
    public function changeStatus(User $actor, int $student_id, string $status, string $reason, Request $request): Student
    {
        $student_status = $this->studentStatus($status);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($actor, $student_id, $student_status, $reason, $request): Student {
            $student = $this->lockedStudent($actor, $student_id);
            $user = User::query()->whereKey($student->user_id)->lockForUpdate()->first();

            if (! $user instanceof User) {
                throw ValidationException::withMessages(['student' => self::MESSAGE_MISSING]);
            }

            if ($student->status === $student_status) {
                throw ValidationException::withMessages(['status' => self::MESSAGE_STATUS_SAME]);
            }

            $user_status = $this->userStatusFor($student_status);
            $before = [
                'status' => $user->status->value,
                'student_status' => $student->status->value,
            ];

            $student->status = $student_status;
            $student->save();
            $user->status = $user_status;
            $user->save();

            if ($user_status !== UserStatus::Active) {
                $this->auth->revokeSessions($user);
            }

            $this->audit->record(
                $actor->id,
                self::ACTION_STATUS_CHANGED,
                'users',
                (int) $user->getKey(),
                $before,
                [
                    'status' => $user_status->value,
                    'student_status' => $student_status->value,
                    'reason' => $reason,
                ],
                $this->audit->ipFromRequest($request),
            );

            return $student->load(['user', 'programme.department']);
        });
    }

    /**
     * Students who can move up one level, and those who cannot.
     *
     * A missing or out-of-scope id refuses the whole preview. The eligible
     * list is what a later commit must still match.
     *
     * @param  list<int>  $student_ids
     * @return array{
     *     eligible: list<array{id: int, matric_no: string, from_level: int, to_level: int}>,
     *     excluded: list<array{id: int, matric_no: string, reason: string}>
     * }
     */
    public function previewPromotion(User $actor, array $student_ids): array
    {
        $this->authoriseCreate($actor);
        $ids = $this->promotionIds($student_ids);
        $students = Student::query()
            ->visibleTo($actor)
            ->with('programme')
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($students->count() !== count($ids)) {
            throw ValidationException::withMessages(['student' => self::MESSAGE_MISSING]);
        }

        return $this->classifyPromotion($students);
    }

    /**
     * Promote the previewed students, or refuse when that set has changed.
     *
     * The eligible rows move in one transaction. One audit row is written
     * for each student. Nothing is written when the check fails.
     *
     * @param  list<int>  $student_ids
     * @param  list<int>  $expected_eligible_ids
     */
    public function commitPromotion(User $actor, array $student_ids, array $expected_eligible_ids, Request $request): void
    {
        $this->authoriseCreate($actor);
        $ids = $this->promotionIds($student_ids);
        $expected = $this->idList($expected_eligible_ids);

        DB::transaction(function () use ($actor, $ids, $expected, $request): void {
            $students = Student::query()
                ->visibleTo($actor)
                ->with('programme')
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($students->count() !== count($ids)) {
                throw ValidationException::withMessages(['student' => self::MESSAGE_MISSING]);
            }

            $preview = $this->classifyPromotion($students);
            $eligible_ids = array_column($preview['eligible'], 'id');
            $expected_sorted = $expected;
            sort($eligible_ids);
            sort($expected_sorted);

            if ($eligible_ids !== $expected_sorted) {
                throw ValidationException::withMessages(['promotion' => self::MESSAGE_PROMOTION_CHANGED]);
            }

            foreach ($preview['eligible'] as $row) {
                $student = $students->firstWhere('id', $row['id']);

                if (! $student instanceof Student) {
                    throw ValidationException::withMessages(['student' => self::MESSAGE_MISSING]);
                }

                $student->level = $row['to_level'];
                $student->save();
                $this->audit->record(
                    $actor->id,
                    self::ACTION_PROMOTED,
                    'students',
                    $row['id'],
                    ['level' => $row['from_level']],
                    ['level' => $row['to_level']],
                    $this->audit->ipFromRequest($request),
                );
            }
        });
    }

    /**
     * The account status that matches a student status.
     *
     * Graduated, Deferred, and Withdrawn deactivate the account, so those
     * students cannot sign in. Whether a graduate should still sign in is
     * an open question for the Registrar.
     */
    public function userStatusFor(StudentStatus $status): UserStatus
    {
        return match ($status) {
            StudentStatus::Active => UserStatus::Active,
            StudentStatus::Suspended => UserStatus::Suspended,
            StudentStatus::Deferred, StudentStatus::Graduated, StudentStatus::Withdrawn => UserStatus::Deactivated,
        };
    }

    /**
     * Refuse a caller who cannot manage student records.
     */
    private function authoriseCreate(User $actor): void
    {
        if (! app(StudentPolicy::class)->create($actor)) {
            throw new AuthorizationException;
        }
    }

    /**
     * A manageable student row, locked for the write.
     */
    private function lockedStudent(User $actor, int $student_id): Student
    {
        $student = Student::query()->visibleTo($actor)->whereKey($student_id)->lockForUpdate()->first();

        if (! $student instanceof Student) {
            if (! app(StudentPolicy::class)->create($actor)) {
                throw new AuthorizationException;
            }

            throw ValidationException::withMessages(['student' => self::MESSAGE_MISSING]);
        }

        if (! app(StudentPolicy::class)->update($actor, $student)) {
            throw new AuthorizationException;
        }

        return $student;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     first_name: string,
     *     last_name: string,
     *     other_names: ?string,
     *     email: string,
     *     phone: ?string,
     *     gender: ?Gender,
     *     date_of_birth: ?string,
     *     state_of_origin: ?string,
     *     address: ?string,
     *     programme_id: int,
     *     level: int,
     *     entry_session_id: ?int
     * }
     */
    private function parsed(array $input, bool $creating, ?Student $student = null): array
    {
        $first = $this->namePart($input['first_name'] ?? null, 'first_name', self::MESSAGE_FIRST_NAME, true);
        $last = $this->namePart($input['last_name'] ?? null, 'last_name', self::MESSAGE_LAST_NAME, true);
        $other = $this->namePart($input['other_names'] ?? null, 'other_names', self::MESSAGE_NAME_LENGTH, false);

        if ($first === null || $last === null) {
            throw ValidationException::withMessages(['first_name' => self::MESSAGE_FIRST_NAME]);
        }

        if (mb_strlen($this->displayName($first, $other, $last)) > 255) {
            throw ValidationException::withMessages(['first_name' => self::MESSAGE_NAME_LENGTH]);
        }

        return [
            'first_name' => $first,
            'last_name' => $last,
            'other_names' => $other,
            'email' => $this->email($input['email'] ?? null),
            'phone' => $this->phone($input['phone'] ?? null),
            'gender' => $this->gender($input['gender'] ?? null),
            'date_of_birth' => $this->dateOfBirth($input['date_of_birth'] ?? null),
            'state_of_origin' => $this->stateOfOrigin($input['state_of_origin'] ?? null),
            'address' => $this->address($input['address'] ?? null),
            'programme_id' => $this->identifier($input['programme_id'] ?? ($student->programme_id ?? null), 'programme_id', self::MESSAGE_PROGRAMME),
            'level' => $this->levelNumber($input['level'] ?? ($student->level ?? null)),
            'entry_session_id' => $this->optionalIdentifier(
                $input['entry_session_id'] ?? ($creating ? null : $student?->entry_session_id),
                $creating,
            ),
        ];
    }

    /**
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     other_names: ?string,
     *     email: string,
     *     phone: ?string,
     *     gender: ?Gender,
     *     date_of_birth: ?string,
     *     state_of_origin: ?string,
     *     address: ?string,
     *     programme_id: int,
     *     level: int,
     *     entry_session_id: ?int
     * }  $parsed
     */
    private function newUser(array $parsed): User
    {
        $user = new User;
        $this->fillUser($user, $parsed);
        $user->status = UserStatus::Active;
        // CredentialService::issue replaces this password before the transaction commits.
        $user->password = Str::password(32);
        $user->must_change_password = false;
        $user->temp_password_expires_at = null;
        $user->remember_token = Str::random(60);

        try {
            $user->save();
        } catch (QueryException $exception) {
            $this->reraiseDuplicateEmail($exception);
        }

        return $user;
    }

    /**
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     other_names: ?string,
     *     email: string,
     *     phone: ?string,
     *     gender: ?Gender,
     *     date_of_birth: ?string,
     *     state_of_origin: ?string,
     *     address: ?string,
     *     programme_id: int,
     *     level: int,
     *     entry_session_id: ?int
     * }  $parsed
     */
    private function newStudent(User $user, Programme $programme, AcademicSession $session, array $parsed, string $matric): Student
    {
        $student = new Student;
        $student->user_id = (int) $user->id;
        $student->matric_no = $matric;
        $student->status = StudentStatus::Active;
        $this->fillStudent($student, $programme, $session, $parsed);
        $student->save();

        return $student;
    }

    /**
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     other_names: ?string,
     *     email: string,
     *     phone: ?string
     * }  $parsed
     */
    private function fillUser(User $user, array $parsed): void
    {
        $user->name = $this->displayName($parsed['first_name'], $parsed['other_names'], $parsed['last_name']);
        $user->email = $parsed['email'];
        $user->phone = $parsed['phone'];
    }

    /**
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     other_names: ?string,
     *     gender: ?Gender,
     *     date_of_birth: ?string,
     *     state_of_origin: ?string,
     *     address: ?string,
     *     level: int
     * }  $parsed
     */
    private function fillStudent(Student $student, Programme $programme, AcademicSession $session, array $parsed): void
    {
        $student->programme_id = (int) $programme->id;
        $student->level = $parsed['level'];
        $student->entry_session_id = (int) $session->id;
        $student->first_name = $parsed['first_name'];
        $student->last_name = $parsed['last_name'];
        $student->other_names = $parsed['other_names'];
        $student->gender = $parsed['gender'];
        $student->date_of_birth = $parsed['date_of_birth'] === null
            ? null
            : Carbon::parse($parsed['date_of_birth'], 'Africa/Lagos');
        $student->state_of_origin = $parsed['state_of_origin'];
        $student->address = $parsed['address'];
    }

    /**
     * Next matric number for this pattern, department, and year.
     *
     * insertOrIgnore then lockForUpdate so two first allocations cannot
     * raise a duplicate-key error. A rolled-back create rolls the counter
     * back with it. An issued number is never edited here.
     */
    private function allocateMatric(Programme $programme, AcademicSession $session): string
    {
        $programme->loadMissing('department');
        $department = $programme->department;
        $department_code = mb_strtoupper(trim((string) $department?->code));

        if ($department_code === '') {
            throw ValidationException::withMessages(['programme_id' => self::MESSAGE_PROGRAMME]);
        }

        $pattern = (string) app(SettingsService::class)->effective()['matric_pattern'];
        $parts = MatricPattern::counterParts($pattern);
        $year = $this->sessionYear($session);
        $key = $this->counterKey($parts, $department_code, $year);
        $sequence = $this->nextSequence($key);
        $rendered = MatricPattern::formatSequence($sequence, $pattern);
        $matric = $pattern;

        if (str_contains($matric, MatricPattern::TOKEN_SEQUENCE_PADDED)) {
            $matric = str_replace(MatricPattern::TOKEN_SEQUENCE_PADDED, $rendered, $matric);
        } else {
            $matric = str_replace(MatricPattern::TOKEN_SEQUENCE, $rendered, $matric);
        }

        $matric = str_replace(
            [MatricPattern::TOKEN_DEPARTMENT, MatricPattern::TOKEN_YEAR],
            [$department_code, $year],
            $matric,
        );
        $matric = mb_strtoupper($matric);

        if (strlen($matric) > 32) {
            throw ValidationException::withMessages(['programme_id' => self::MESSAGE_MATRIC_LENGTH]);
        }

        return $matric;
    }

    /**
     * @param  array{department: bool, year: bool, padded: bool}  $parts
     */
    private function counterKey(array $parts, string $department_code, string $year): string
    {
        $key = 'matric:'.($parts['department'] ? $department_code : '-').':'.($parts['year'] ? $year : '-');

        if (strlen($key) > 64) {
            throw ValidationException::withMessages(['programme_id' => self::MESSAGE_MATRIC_LENGTH]);
        }

        return $key;
    }

    /**
     * Insert the counter when it is missing, then lock and increment it.
     */
    private function nextSequence(string $key): int
    {
        $now = now();

        DB::table('counters')->insertOrIgnore([
            'key' => $key,
            'value' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $counter = Counter::query()->where('key', $key)->lockForUpdate()->first();

        if (! $counter instanceof Counter) {
            throw ValidationException::withMessages(['programme_id' => self::MESSAGE_MATRIC_LENGTH]);
        }

        $next = (int) $counter->value + 1;
        $counter->value = $next;
        $counter->save();

        return $next;
    }

    private function programme(int $programme_id): Programme
    {
        $programme = Programme::query()->with('department')->whereKey($programme_id)->first();

        if (! $programme instanceof Programme) {
            throw ValidationException::withMessages(['programme_id' => self::MESSAGE_PROGRAMME]);
        }

        return $programme;
    }

    /**
     * The chosen session, or the current session when the id is empty.
     */
    private function entrySession(?int $session_id): AcademicSession
    {
        if ($session_id === null) {
            $current = AcademicSession::query()->where('is_current', true)->lockForUpdate()->first();

            if (! $current instanceof AcademicSession) {
                throw ValidationException::withMessages(['entry_session_id' => self::MESSAGE_SESSION]);
            }

            return $current;
        }

        $session = AcademicSession::query()->whereKey($session_id)->lockForUpdate()->first();

        if (! $session instanceof AcademicSession) {
            throw ValidationException::withMessages(['entry_session_id' => self::MESSAGE_SESSION_INVALID]);
        }

        return $session;
    }

    private function sessionYear(AcademicSession $session): string
    {
        if (preg_match('/^(\d{4})\/\d{4}$/', $session->name, $matches) !== 1) {
            throw ValidationException::withMessages(['entry_session_id' => self::MESSAGE_SESSION_INVALID]);
        }

        return $matches[1];
    }

    /**
     * Distinct positive ids, capped at 200.
     *
     * @param  list<mixed>  $student_ids
     * @return list<int>
     */
    private function promotionIds(array $student_ids): array
    {
        $ids = $this->idList($student_ids);

        if ($ids === []) {
            throw ValidationException::withMessages(['student' => self::MESSAGE_MISSING]);
        }

        if (count($ids) > self::PROMOTION_CAP) {
            throw ValidationException::withMessages(['promotion' => self::MESSAGE_PROMOTION_CAP]);
        }

        return $ids;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<int>
     */
    private function idList(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            if (is_int($value) && $value > 0) {
                $ids[] = $value;

                continue;
            }

            if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
                $ids[] = (int) $value;

                continue;
            }

            throw ValidationException::withMessages(['student' => self::MESSAGE_MISSING]);
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Split a locked set into students who move up and students who stay.
     *
     * @param  Collection<int, Student>  $students
     * @return array{
     *     eligible: list<array{id: int, matric_no: string, from_level: int, to_level: int}>,
     *     excluded: list<array{id: int, matric_no: string, reason: string}>
     * }
     */
    private function classifyPromotion(Collection $students): array
    {
        $eligible = [];
        $excluded = [];

        foreach ($students as $student) {
            $reason = $this->promotionBlock($student);

            if ($reason !== null) {
                $excluded[] = [
                    'id' => (int) $student->getKey(),
                    'matric_no' => $student->matric_no,
                    'reason' => $reason,
                ];

                continue;
            }

            $eligible[] = [
                'id' => (int) $student->getKey(),
                'matric_no' => $student->matric_no,
                'from_level' => (int) $student->level,
                'to_level' => (int) $student->level + 100,
            ];
        }

        return [
            'eligible' => $eligible,
            'excluded' => $excluded,
        ];
    }

    /**
     * Why this student stays at the current level, or null when they can move.
     */
    private function promotionBlock(Student $student): ?string
    {
        if ($student->status !== StudentStatus::Active) {
            return self::MESSAGE_PROMOTION_INACTIVE;
        }

        $years = min((int) $student->programme->duration_years, 6);
        $next = (int) $student->level + 100;

        if ($next > ($years * 100)) {
            return self::MESSAGE_PROMOTION_LEVEL;
        }

        return null;
    }

    /**
     * Levels run from 100 up to the programme length, and never past 600.
     */
    private function assertLevel(Programme $programme, int $level): void
    {
        $years = min((int) $programme->duration_years, 6);
        $allowed = [];

        for ($year = 1; $year <= $years; $year++) {
            $allowed[] = $year * 100;
        }

        if (! in_array($level, $allowed, true)) {
            throw ValidationException::withMessages([
                'level' => sprintf(self::MESSAGE_LEVEL, (int) $programme->duration_years),
            ]);
        }
    }

    private function assertEmailAvailable(string $email, ?int $ignore_user_id): void
    {
        $query = User::query()->whereRaw('lower(email) = ?', [$email]);

        if ($ignore_user_id !== null) {
            $query->whereKeyNot($ignore_user_id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_EMAIL_TAKEN]);
        }
    }

    /**
     * Turn a duplicate email index error into a field message.
     */
    private function reraiseDuplicateEmail(QueryException $exception): never
    {
        $state = $exception->errorInfo[0] ?? null;

        if ($state === '23000' && str_contains($exception->getMessage(), 'users_email_unique')) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_EMAIL_TAKEN]);
        }

        throw $exception;
    }

    private function displayName(string $first, ?string $other, string $last): string
    {
        $parts = [$first];

        if ($other !== null && $other !== '') {
            $parts[] = $other;
        }

        $parts[] = $last;

        return implode(' ', $parts);
    }

    private function namePart(mixed $value, string $field, string $message, bool $required): ?string
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => $message]);
            }

            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => $message]);
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim($value));
        $text = is_string($collapsed) ? $collapsed : '';

        if ($text === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => $message]);
            }

            return null;
        }

        if (mb_strlen($text) > 100) {
            throw ValidationException::withMessages([$field => self::MESSAGE_NAME_LENGTH]);
        }

        return $text;
    }

    private function email(mixed $value): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_EMAIL]);
        }

        $email = mb_strtolower(trim($value));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255) {
            throw ValidationException::withMessages(['email' => self::MESSAGE_EMAIL]);
        }

        return $email;
    }

    private function phone(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages(['phone' => Phone::MESSAGE]);
        }

        try {
            return Phone::normalise($value);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['phone' => Phone::MESSAGE]);
        }
    }

    private function gender(mixed $value): ?Gender
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages(['gender' => self::MESSAGE_GENDER]);
        }

        $gender = Gender::tryFrom(trim($value));

        if (! $gender instanceof Gender) {
            throw ValidationException::withMessages(['gender' => self::MESSAGE_GENDER]);
        }

        return $gender;
    }

    private function dateOfBirth(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages(['date_of_birth' => self::MESSAGE_DATE]);
        }

        try {
            $date = Carbon::parse(trim($value), 'Africa/Lagos')->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages(['date_of_birth' => self::MESSAGE_DATE]);
        }

        if ($date->year < 1900 || $date->greaterThan(Carbon::now('Africa/Lagos')->startOfDay())) {
            throw ValidationException::withMessages(['date_of_birth' => self::MESSAGE_DATE]);
        }

        return $date->toDateString();
    }

    private function stateOfOrigin(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages(['state_of_origin' => self::MESSAGE_STATE]);
        }

        $state = NigerianStates::canonical($value);

        if ($state === null) {
            throw ValidationException::withMessages(['state_of_origin' => self::MESSAGE_STATE]);
        }

        return $state;
    }

    private function address(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages(['address' => self::MESSAGE_ADDRESS]);
        }

        $text = trim($value);

        if ($text === '') {
            return null;
        }

        if (mb_strlen($text) > 1000) {
            throw ValidationException::withMessages(['address' => self::MESSAGE_ADDRESS]);
        }

        return $text;
    }

    private function identifier(mixed $value, string $field, string $message): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }

        throw ValidationException::withMessages([$field => $message]);
    }

    private function optionalIdentifier(mixed $value, bool $blank_means_current): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->identifier($value, 'entry_session_id', $blank_means_current ? self::MESSAGE_SESSION : self::MESSAGE_SESSION_INVALID);
    }

    private function levelNumber(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            return (int) $value;
        }

        throw ValidationException::withMessages([
            'level' => self::MESSAGE_LEVEL_NUMBER,
        ]);
    }

    private function studentStatus(string $status): StudentStatus
    {
        $parsed = StudentStatus::tryFrom(trim($status));

        if (! $parsed instanceof StudentStatus) {
            throw ValidationException::withMessages(['status' => self::MESSAGE_STATUS]);
        }

        return $parsed;
    }

    private function reason(string $reason): string
    {
        $text = trim($reason);

        if ($text === '' || mb_strlen($text) > 500) {
            throw ValidationException::withMessages(['reason' => self::MESSAGE_REASON]);
        }

        return $text;
    }

    /**
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     other_names: ?string,
     *     email: string,
     *     phone: ?string,
     *     gender: ?Gender,
     *     date_of_birth: ?string,
     *     state_of_origin: ?string,
     *     address: ?string,
     *     level: int
     * }  $parsed
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function creationAudit(array $parsed, Programme $programme, AcademicSession $session, string $matric): array
    {
        $after = $this->parsedMap($parsed, $programme, $session, $matric, StudentStatus::Active);
        $before = array_fill_keys(array_keys($after), null);

        return PersonalAudit::diff($before, $after, self::PERSONAL_FIELDS, self::VALUE_FIELDS);
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldMap(Student $student, User $user): array
    {
        return [
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'other_names' => $student->other_names,
            'gender' => $student->gender instanceof Gender ? $student->gender->value : null,
            'phone' => $user->phone,
            'date_of_birth' => $student->date_of_birth?->toDateString(),
            'state_of_origin' => $student->state_of_origin,
            'address' => $student->address,
            'email' => $user->email,
            'status' => $student->status->value,
            'level' => (int) $student->level,
            'programme_id' => (int) $student->programme_id,
            'entry_session_id' => (int) $student->entry_session_id,
            'matric_no' => $student->matric_no,
        ];
    }

    /**
     * @param  array{
     *     first_name: string,
     *     last_name: string,
     *     other_names: ?string,
     *     email: string,
     *     phone: ?string,
     *     gender: ?Gender,
     *     date_of_birth: ?string,
     *     state_of_origin: ?string,
     *     address: ?string,
     *     level: int
     * }  $parsed
     * @return array<string, mixed>
     */
    private function parsedMap(array $parsed, Programme $programme, AcademicSession $session, string $matric, StudentStatus $status): array
    {
        return [
            'first_name' => $parsed['first_name'],
            'last_name' => $parsed['last_name'],
            'other_names' => $parsed['other_names'],
            'gender' => $parsed['gender'] instanceof Gender ? $parsed['gender']->value : null,
            'phone' => $parsed['phone'],
            'date_of_birth' => $parsed['date_of_birth'],
            'state_of_origin' => $parsed['state_of_origin'],
            'address' => $parsed['address'],
            'email' => $parsed['email'],
            'status' => $status->value,
            'level' => $parsed['level'],
            'programme_id' => (int) $programme->id,
            'entry_session_id' => (int) $session->id,
            'matric_no' => $matric,
        ];
    }
}
