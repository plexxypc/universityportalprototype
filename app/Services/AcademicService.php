<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Semester;
use App\Models\User;
use App\Policies\AcademicSessionPolicy;
use App\Policies\SemesterPolicy;
use App\Support\Audit;
use App\Support\Dates;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Academic sessions.
 *
 * A name is two years, and the second year is the next year. At most one
 * session is current. Switching the current session clears the old row
 * first, in the same transaction.
 */
class AcademicService
{
    public const string ACTION_CREATED = 'academic_session.created';

    public const string ACTION_UPDATED = 'academic_session.updated';

    public const string ACTION_DELETED = 'academic_session.deleted';

    public const string MESSAGE_NAME = 'Enter a session name like 2026/2027, with the second year one greater than the first.';

    public const string MESSAGE_DUPLICATE = 'That session already exists.';

    public const string MESSAGE_CURRENT = 'Choose another current session before clearing this one.';

    public const string MESSAGE_FIRST = 'The first session must be the current session.';

    public const string MESSAGE_MISSING = 'That session was not found.';

    public const string MESSAGE_IN_USE = 'This session is used by a semester, student, applicant, fee structure, or invoice, so it cannot be deleted.';

    public const string SEMESTER_CREATED = 'semester.created';

    public const string SEMESTER_UPDATED = 'semester.updated';

    public const string SEMESTER_DELETED = 'semester.deleted';

    public const string MESSAGE_SEMESTER_NAME = 'Enter a semester name of 1 to 32 characters.';

    public const string MESSAGE_SEMESTER_DUPLICATE = 'That semester already exists in this session.';

    public const string MESSAGE_SEMESTER_ACTIVE = 'Choose another active semester before clearing this one.';

    public const string MESSAGE_SEMESTER_FIRST = 'The first semester must be the active semester.';

    public const string MESSAGE_SEMESTER_MISSING = 'That semester was not found.';

    public const string MESSAGE_SEMESTER_IN_USE = 'This semester is in use and cannot be deleted.';

    public const string MESSAGE_DEADLINE = 'Enter a valid date and time.';

    public const string MESSAGE_ADD_DROP = 'The add/drop deadline must be on or after the registration deadline.';

    public const string MESSAGE_SEMESTER_NUMBER = 'Choose semester 1 or semester 2.';

    public const string MESSAGE_SEMESTER_NUMBER_TAKEN = 'That semester number is already used in this session.';

    public const string MESSAGE_SEMESTER_NUMBER_MISSING = 'The active semester has no semester number. Set it before students can register.';

    /**
     * Plain-words warning when saving an active semester also changes the current session.
     *
     * Returns null when this save does not switch the current session.
     */
    public function activationNotice(int $session_id, string $semester_name, bool $is_active): ?string
    {
        if (! $is_active) {
            return null;
        }

        $session = AcademicSession::query()->find($session_id);

        if (! $session instanceof AcademicSession || $session->is_current) {
            return null;
        }

        $current_name = AcademicSession::query()->where('is_current', true)->value('name');
        $current = is_string($current_name) && $current_name !== '' ? $current_name : 'the current session';
        $semester_name = trim($semester_name);

        return "This will make {$semester_name} the active semester and make {$session->name} the current session instead of {$current}.";
    }

    public function __construct(private readonly Audit $audit) {}

    /**
     * Create one session.
     */
    public function createSession(User $actor, string $name, bool $is_current, Request $request): AcademicSession
    {
        $this->allow($actor, 'create');
        $name = $this->sessionName($name);

        return DB::transaction(function () use ($actor, $name, $is_current, $request): AcademicSession {
            $current = AcademicSession::query()->where('is_current', true)->lockForUpdate()->first();

            if (AcademicSession::query()->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => self::MESSAGE_DUPLICATE]);
            }

            if (! $is_current && ! $current instanceof AcademicSession) {
                throw ValidationException::withMessages(['is_current' => self::MESSAGE_FIRST]);
            }

            if ($is_current && $current instanceof AcademicSession) {
                $current->is_current = false;
                $current->save();
            }

            $session = new AcademicSession;
            $session->name = $name;
            $session->is_current = $is_current;
            $session->save();

            $this->audit->record(
                $actor->id,
                self::ACTION_CREATED,
                'academic_sessions',
                (int) $session->getKey(),
                null,
                $this->snapshot($session),
                $this->audit->ipFromRequest($request),
            );

            return $session;
        });
    }

    /**
     * Rename a session or move the current flag.
     */
    public function updateSession(User $actor, int $session_id, string $name, bool $is_current, Request $request): AcademicSession
    {
        return DB::transaction(function () use ($actor, $session_id, $name, $is_current, $request): AcademicSession {
            $session = $this->lockedSession($actor, $session_id, 'update');
            $name = $this->sessionName($name);
            $before = $this->snapshot($session);

            $taken = AcademicSession::query()
                ->where('name', $name)
                ->whereKeyNot($session->id)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['name' => self::MESSAGE_DUPLICATE]);
            }

            if ($is_current && ! $session->is_current) {
                AcademicSession::query()
                    ->where('is_current', true)
                    ->lockForUpdate()
                    ->update(['is_current' => false]);
                $session->is_current = true;
            }

            if (! $is_current && $session->is_current) {
                throw ValidationException::withMessages(['is_current' => self::MESSAGE_CURRENT]);
            }

            $session->name = $name;
            $session->save();

            $this->audit->record(
                $actor->id,
                self::ACTION_UPDATED,
                'academic_sessions',
                (int) $session->getKey(),
                $before,
                $this->snapshot($session),
                $this->audit->ipFromRequest($request),
            );

            return $session;
        });
    }

    /**
     * Delete a session that nothing else references.
     */
    public function deleteSession(User $actor, int $session_id, Request $request): void
    {
        DB::transaction(function () use ($actor, $session_id, $request): void {
            $session = $this->lockedSession($actor, $session_id, 'delete');

            if ($this->sessionIsReferenced($session)) {
                throw ValidationException::withMessages(['session' => self::MESSAGE_IN_USE]);
            }

            if ($session->is_current && AcademicSession::query()->whereKeyNot($session->id)->exists()) {
                throw ValidationException::withMessages(['session' => self::MESSAGE_CURRENT]);
            }

            $before = $this->snapshot($session);
            $session_key = (int) $session->getKey();
            $session->delete();

            $this->audit->record(
                $actor->id,
                self::ACTION_DELETED,
                'academic_sessions',
                $session_key,
                $before,
                null,
                $this->audit->ipFromRequest($request),
            );
        });
    }

    /**
     * Create one semester. Making it active also makes its session current.
     */
    public function createSemester(
        User $actor,
        int $session_id,
        string $name,
        bool $is_active,
        mixed $registration_deadline,
        mixed $add_drop_deadline,
        int $semester_no,
        Request $request,
    ): Semester {
        if (! app(SemesterPolicy::class)->create($actor)) {
            throw new AuthorizationException;
        }
        $name = $this->semesterName($name);
        $semester_no = $this->semesterNumber($semester_no);
        $registration = $this->deadline($registration_deadline, 'registration_deadline');
        $add_drop = $this->deadline($add_drop_deadline, 'add_drop_deadline');
        $this->deadlinesInOrder($registration, $add_drop);

        return DB::transaction(function () use ($actor, $session_id, $name, $is_active, $semester_no, $registration, $add_drop, $request): Semester {
            $session = AcademicSession::query()->visibleTo($actor)->whereKey($session_id)->lockForUpdate()->first();

            if (! $session instanceof AcademicSession) {
                throw ValidationException::withMessages(['session_id' => self::MESSAGE_MISSING]);
            }

            if (Semester::query()->where('session_id', $session->id)->where('name', $name)->exists()) {
                throw ValidationException::withMessages(['name' => self::MESSAGE_SEMESTER_DUPLICATE]);
            }

            $this->guardSemesterNumber($session->id, $semester_no, null);

            $active = Semester::query()->where('is_active', true)->lockForUpdate()->first();

            if (! $is_active && ! $active instanceof Semester) {
                throw ValidationException::withMessages(['is_active' => self::MESSAGE_SEMESTER_FIRST]);
            }

            if ($is_active) {
                $this->clearActiveSemester();
                $this->makeSessionCurrent($session);
            }

            $semester = new Semester;
            $semester->session_id = $session->id;
            $semester->name = $name;
            $semester->semester_no = $semester_no;
            $semester->is_active = $is_active;
            $semester->registration_deadline = $this->lagosInstant($registration, 'registration_deadline');
            $semester->add_drop_deadline = $this->lagosInstant($add_drop, 'add_drop_deadline');
            $semester->save();

            $this->audit->record(
                $actor->id,
                self::SEMESTER_CREATED,
                'semesters',
                (int) $semester->getKey(),
                null,
                $this->semesterSnapshot($semester),
                $this->audit->ipFromRequest($request),
            );

            return $semester;
        });
    }

    /**
     * Rename a semester, move its deadlines, or make it the active semester.
     *
     * Activating a semester in another session also switches the current
     * session in this same transaction.
     */
    public function updateSemester(
        User $actor,
        int $semester_id,
        string $name,
        bool $is_active,
        mixed $registration_deadline,
        mixed $add_drop_deadline,
        int $semester_no,
        Request $request,
    ): Semester {
        $name = $this->semesterName($name);
        $semester_no = $this->semesterNumber($semester_no);
        $registration = $this->deadline($registration_deadline, 'registration_deadline');
        $add_drop = $this->deadline($add_drop_deadline, 'add_drop_deadline');
        $this->deadlinesInOrder($registration, $add_drop);

        return DB::transaction(function () use ($actor, $semester_id, $name, $is_active, $semester_no, $registration, $add_drop, $request): Semester {
            $semester = $this->lockedSemester($actor, $semester_id);
            $before = $this->semesterSnapshot($semester);
            $session = AcademicSession::query()->whereKey($semester->session_id)->lockForUpdate()->first();

            if (! $session instanceof AcademicSession) {
                throw ValidationException::withMessages(['session_id' => self::MESSAGE_MISSING]);
            }

            $taken = Semester::query()
                ->where('session_id', $session->id)
                ->where('name', $name)
                ->whereKeyNot($semester->id)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['name' => self::MESSAGE_SEMESTER_DUPLICATE]);
            }

            $this->guardSemesterNumber($session->id, $semester_no, (int) $semester->getKey());

            if ($is_active && ! $semester->is_active) {
                $this->clearActiveSemester();
                $this->makeSessionCurrent($session);
                $semester->is_active = true;
            }

            if (! $is_active && $semester->is_active) {
                throw ValidationException::withMessages(['is_active' => self::MESSAGE_SEMESTER_ACTIVE]);
            }

            $semester->name = $name;
            $semester->semester_no = $semester_no;
            $semester->registration_deadline = $this->lagosInstant($registration, 'registration_deadline');
            $semester->add_drop_deadline = $this->lagosInstant($add_drop, 'add_drop_deadline');
            $semester->save();

            $this->audit->record(
                $actor->id,
                self::SEMESTER_UPDATED,
                'semesters',
                (int) $semester->getKey(),
                $before,
                $this->semesterSnapshot($semester),
                $this->audit->ipFromRequest($request),
            );

            return $semester->refresh();
        });
    }

    /**
     * Delete a semester that nothing else references.
     */
    public function deleteSemester(User $actor, int $semester_id, Request $request): void
    {
        DB::transaction(function () use ($actor, $semester_id, $request): void {
            $semester = $this->lockedSemester($actor, $semester_id);

            if ($semester->courseRegistrations()->exists()
                || $semester->attendanceSessions()->exists()
                || $semester->results()->exists()
                || $semester->courseAssignments()->exists()
                || $semester->examTimetable()->exists()) {
                throw ValidationException::withMessages(['semester' => self::MESSAGE_SEMESTER_IN_USE]);
            }

            if ($semester->is_active && Semester::query()->whereKeyNot($semester->id)->exists()) {
                throw ValidationException::withMessages(['semester' => self::MESSAGE_SEMESTER_ACTIVE]);
            }

            $before = $this->semesterSnapshot($semester);
            $semester_key = (int) $semester->getKey();
            $semester->delete();

            $this->audit->record(
                $actor->id,
                self::SEMESTER_DELETED,
                'semesters',
                $semester_key,
                $before,
                null,
                $this->audit->ipFromRequest($request),
            );
        });
    }

    /**
     * A visible session row, locked for the write.
     */
    private function lockedSession(User $actor, int $session_id, string $ability): AcademicSession
    {
        $session = AcademicSession::query()->visibleTo($actor)->whereKey($session_id)->lockForUpdate()->first();

        if (! $session instanceof AcademicSession) {
            $this->allow($actor, 'create');

            throw ValidationException::withMessages(['session' => self::MESSAGE_MISSING]);
        }

        $this->allow($actor, $ability, $session);

        return $session;
    }

    /**
     * Refuse an account that may not perform this write.
     */
    private function allow(User $actor, string $ability, ?AcademicSession $session = null): void
    {
        $policy = app(AcademicSessionPolicy::class);
        $allowed = match ($ability) {
            'create' => $policy->create($actor),
            'update' => $session instanceof AcademicSession && $policy->update($actor, $session),
            'delete' => $session instanceof AcademicSession && $policy->delete($actor, $session),
            default => false,
        };

        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    /**
     * Accept 2026/2027 only when the second year is the next year.
     */
    private function sessionName(string $name): string
    {
        $name = trim($name);

        if (preg_match('/^(\d{4})\/(\d{4})$/', $name, $matches) !== 1) {
            throw ValidationException::withMessages(['name' => self::MESSAGE_NAME]);
        }

        if ((int) $matches[2] !== (int) $matches[1] + 1) {
            throw ValidationException::withMessages(['name' => self::MESSAGE_NAME]);
        }

        return $name;
    }

    /**
     * Whether a semester, student, applicant, fee structure, or invoice uses this session.
     */
    private function sessionIsReferenced(AcademicSession $session): bool
    {
        return $session->semesters()->exists()
            || $session->students()->exists()
            || $session->applicants()->exists()
            || $session->feeStructures()->exists()
            || $session->invoices()->exists();
    }

    /**
     * Fields stored on the audit row.
     *
     * @return array{name: string, is_current: bool}
     */
    private function snapshot(AcademicSession $session): array
    {
        return [
            'name' => $session->name,
            'is_current' => (bool) $session->is_current,
        ];
    }

    /**
     * A visible semester row, locked for the write.
     */
    private function lockedSemester(User $actor, int $semester_id): Semester
    {
        $semester = Semester::query()->visibleTo($actor)->whereKey($semester_id)->lockForUpdate()->first();

        if (! $semester instanceof Semester) {
            if (! app(SemesterPolicy::class)->create($actor)) {
                throw new AuthorizationException;
            }

            throw ValidationException::withMessages(['semester' => self::MESSAGE_SEMESTER_MISSING]);
        }

        if (! app(SemesterPolicy::class)->update($actor, $semester)) {
            throw new AuthorizationException;
        }

        return $semester;
    }

    /**
     * Clear the active flag before another semester becomes active.
     */
    private function clearActiveSemester(): void
    {
        Semester::query()->where('is_active', true)->lockForUpdate()->update(['is_active' => false]);
    }

    /**
     * Make this session current, clearing the previous one first.
     */
    private function makeSessionCurrent(AcademicSession $session): void
    {
        if ($session->is_current) {
            return;
        }

        AcademicSession::query()->where('is_current', true)->lockForUpdate()->update(['is_current' => false]);
        $session->is_current = true;
        $session->save();
    }

    /**
     * Programme courses use semester 1 or 2. The name stays free text.
     */
    private function semesterNumber(int $semester_no): int
    {
        if (! in_array($semester_no, [1, 2], true)) {
            throw ValidationException::withMessages(['semester_no' => self::MESSAGE_SEMESTER_NUMBER]);
        }

        return $semester_no;
    }

    /**
     * One session holds each semester number once.
     */
    private function guardSemesterNumber(int $session_id, int $semester_no, ?int $ignore_id): void
    {
        $query = Semester::query()
            ->where('session_id', $session_id)
            ->where('semester_no', $semester_no);

        if ($ignore_id !== null) {
            $query->whereKeyNot($ignore_id);
        }

        if ($query->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['semester_no' => self::MESSAGE_SEMESTER_NUMBER_TAKEN]);
        }
    }

    /**
     * A semester name is free text, from 1 to 32 characters.
     */
    private function semesterName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || strlen($name) > 32 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw ValidationException::withMessages(['name' => self::MESSAGE_SEMESTER_NAME]);
        }

        return $name;
    }

    /**
     * Store a deadline as Africa/Lagos wall time, or null when it is blank.
     */
    private function deadline(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $stored = Dates::storageDateTime($value);

        if ($stored === null) {
            throw ValidationException::withMessages([$field => self::MESSAGE_DEADLINE]);
        }

        return $stored;
    }

    /**
     * The add/drop deadline is on or after registration when both are set.
     */
    private function deadlinesInOrder(?string $registration, ?string $add_drop): void
    {
        if ($registration === null || $add_drop === null || $add_drop >= $registration) {
            return;
        }

        throw ValidationException::withMessages(['add_drop_deadline' => self::MESSAGE_ADD_DROP]);
    }

    /**
     * A Lagos wall-clock instant for a stored deadline string.
     */
    private function lagosInstant(?string $stored, string $field): ?Carbon
    {
        if ($stored === null) {
            return null;
        }

        $instant = Carbon::createFromFormat('Y-m-d H:i:s', $stored, Dates::TIMEZONE);

        if (! $instant instanceof Carbon) {
            throw ValidationException::withMessages([$field => self::MESSAGE_DEADLINE]);
        }

        return $instant;
    }

    /**
     * Fields stored on the semester audit row.
     *
     * @return array{session_id: int, name: string, semester_no: int, is_active: bool, session_is_current: bool, registration_deadline: ?string, add_drop_deadline: ?string}
     */
    private function semesterSnapshot(Semester $semester): array
    {
        return [
            'session_id' => (int) $semester->session_id,
            'name' => $semester->name,
            'semester_no' => (int) $semester->semesterNumber(),
            'is_active' => (bool) $semester->is_active,
            'session_is_current' => (bool) AcademicSession::query()->whereKey($semester->session_id)->value('is_current'),
            'registration_deadline' => Dates::storageDateTime($semester->registration_deadline),
            'add_drop_deadline' => Dates::storageDateTime($semester->add_drop_deadline),
        ];
    }
}
