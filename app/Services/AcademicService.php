<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\User;
use App\Policies\AcademicSessionPolicy;
use App\Support\Audit;
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
}
