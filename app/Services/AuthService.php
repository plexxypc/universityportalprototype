<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Student;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The only authenticator.
 *
 * The portal login and the staff panel both come through here.
 * Filament does not authenticate on its own.
 */
final class AuthService
{
    /**
     * Remember the audit helper. It does not open a transaction.
     */
    public function __construct(private readonly Audit $audits) {}

    /**
     * Shown for every failed sign-in, including a lockout. The text does not say why.
     */
    public const string FAILURE_MESSAGE = 'Invalid credentials.';

    /**
     * Failures for one identifier from one socket address.
     */
    private const int PAIR_MAX_ATTEMPTS = 5;

    private const int PAIR_DECAY_SECONDS = 60;

    /**
     * Failures for one identifier from any address.
     */
    private const int IDENTIFIER_MAX_ATTEMPTS = 10;

    private const int IDENTIFIER_DECAY_SECONDS = 900;

    /**
     * Bcrypt hash used when there is no user hash to check.
     */
    private static ?string $dummy_hash = null;

    /**
     * Sign in, or fail with no reason.
     *
     * The password is checked once on every attempt. A missing user, and a
     * locked identifier, are checked against the same dummy hash.
     */
    public function attempt(Request $request, string $identifier, string $password): LoginResult
    {
        $normalised = $this->normaliseIdentifier($identifier);
        $address = $this->clientAddress($request);
        $locked = $this->isLocked($normalised, $address);
        $user = $this->findUser($normalised);
        $password_matches = $this->passwordMatches($locked ? null : $user, $password);

        if ($locked || ! $user instanceof User || ! $password_matches || ! $this->maySignIn($user)) {
            if (! $locked) {
                $this->recordFailure($normalised, $address);
                $this->auditIdentifierLockout($request, $user, $normalised);
            }

            return LoginResult::failed();
        }

        $destination = $this->homePath($user);

        if ($destination === null) {
            $this->discardSignedInSession($request, $user);
            $this->recordFailure($normalised, $address);
            $this->auditIdentifierLockout($request, $user, $normalised);

            return LoginResult::failed();
        }

        $previous_login = $user->last_login_at?->toDateTimeString();
        $user_id = $user->id;

        DB::transaction(function () use ($request, $user, $previous_login, $user_id): void {
            $this->completeSignIn($request, $user);
            $user->refresh();

            $this->audits->record(
                $user_id,
                AuditService::ACTION_LOGIN,
                AuditService::ENTITY_USERS,
                $user_id,
                ['last_login_at' => $previous_login],
                ['last_login_at' => $user->last_login_at?->toDateTimeString()],
                $this->audits->ipFromRequest($request),
            );
        });

        $this->clearFailures($normalised, $address);

        return LoginResult::succeeded($destination);
    }

    /**
     * Where a signed-in user continues.
     *
     * Any staff role wins, including when a students row also exists.
     * A student-only user goes to the student placeholder. Null means the
     * account has no staff role and no students row, so the caller signs
     * them out instead of redirecting.
     */
    public function homePath(User $user): ?string
    {
        if ($user->hasStaffRole()) {
            return '/staff';
        }

        if ($user->hasRole(Role::Student)) {
            return '/student';
        }

        return null;
    }

    /**
     * End this session and record auth.logout when a user is signed in.
     *
     * The same method serves the portal logout and a middleware sign-out.
     * It does not record why the session ended.
     */
    public function logout(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return;
        }

        $user_id = $user->id;
        $ip = $this->audits->ipFromRequest($request);

        DB::transaction(function () use ($request, $user_id, $ip): void {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $this->audits->record(
                $user_id,
                AuditService::ACTION_LOGOUT,
                AuditService::ENTITY_USERS,
                $user_id,
                null,
                null,
                $ip,
            );
        });
    }

    /**
     * End every other session for this user and rotate the remember token.
     *
     * The current session stays signed in. There is no screen for this yet.
     * The change-password action calls it after regenerating the session.
     */
    public function signOutOtherSessions(Request $request, User $user): void
    {
        $user->forceFill([
            'remember_token' => Str::random(60),
        ])->save();

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', $request->session()->getId())
            ->delete();
    }

    /**
     * Trim, then lowercase an email or uppercase a matric number.
     *
     * An identifier that contains "@" is an email. Anything else is a
     * matric number: whitespace is removed and letters are uppercased.
     */
    private function normaliseIdentifier(string $identifier): string
    {
        $trimmed = trim($identifier);

        if (str_contains($trimmed, '@')) {
            return mb_strtolower($trimmed);
        }

        $collapsed = preg_replace('/\s+/u', '', $trimmed);

        return mb_strtoupper(is_string($collapsed) ? $collapsed : $trimmed);
    }

    /**
     * Resolve the account for this identifier.
     *
     * Email looks up users. A matric number looks up students and uses that user.
     */
    private function findUser(string $normalised): ?User
    {
        if (str_contains($normalised, '@')) {
            return User::query()
                ->whereRaw('lower(email) = ?', [$normalised])
                ->first();
        }

        $student = Student::query()
            ->with('user')
            ->where('matric_no', $normalised)
            ->first();

        return $student?->user;
    }

    /**
     * Check the password once.
     *
     * A missing user is checked against the dummy hash.
     */
    private function passwordMatches(?User $user, string $password): bool
    {
        $hash = $user instanceof User
            ? (string) $user->getAuthPassword()
            : $this->dummyHash();

        return Hash::check($password, $hash);
    }

    /**
     * Whether this account is allowed to open a session.
     *
     * Active is required. A temporary password that has already expired
     * is refused while must_change_password is still set.
     */
    private function maySignIn(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($user->status !== UserStatus::Active) {
            return false;
        }

        if ($user->must_change_password && $user->temp_password_expires_at?->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Open the session, then record the login time.
     */
    private function completeSignIn(Request $request, User $user): void
    {
        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
    }

    /**
     * Sign the user in and straight back out.
     *
     * Used when the password is right but the account has no staff role
     * and no students row. Nothing is left in the session, and last_login_at
     * stays empty.
     */
    private function discardSignedInSession(Request $request, User $user): void
    {
        Auth::login($user);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Socket address. A client-supplied X-Forwarded-For is ignored.
     */
    private function clientAddress(Request $request): string
    {
        $address = $request->server->get('REMOTE_ADDR');

        return is_string($address) && $address !== '' ? $address : '0';
    }

    /**
     * Whether either login limit is already spent.
     *
     * The pair limit is 5 failures per minute. The identifier limit is
     * 10 failures per 15 minutes, from any address.
     */
    private function isLocked(string $normalised, string $address): bool
    {
        return RateLimiter::tooManyAttempts($this->pairKey($normalised, $address), self::PAIR_MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts($this->identifierKey($normalised), self::IDENTIFIER_MAX_ATTEMPTS);
    }

    /**
     * Write auth.lockout once, on the failure that reaches the identifier cap.
     *
     * An identifier that matches no account writes nothing. The pair limit
     * writes nothing. The typed identifier and its HMAC are not stored.
     */
    private function auditIdentifierLockout(Request $request, ?User $user, string $normalised): void
    {
        if (! $user instanceof User) {
            return;
        }

        if (RateLimiter::attempts($this->identifierKey($normalised)) !== self::IDENTIFIER_MAX_ATTEMPTS) {
            return;
        }

        $user_id = $user->id;
        $ip = $this->audits->ipFromRequest($request);

        DB::transaction(function () use ($user_id, $ip): void {
            $this->audits->record(
                null,
                AuditService::ACTION_LOCKOUT,
                AuditService::ENTITY_USERS,
                $user_id,
                null,
                null,
                $ip,
            );
        });
    }

    /**
     * Count one failure against both limits.
     */
    private function recordFailure(string $normalised, string $address): void
    {
        RateLimiter::hit($this->pairKey($normalised, $address), self::PAIR_DECAY_SECONDS);
        RateLimiter::hit($this->identifierKey($normalised), self::IDENTIFIER_DECAY_SECONDS);
    }

    /**
     * Forget both limits after a successful sign-in.
     */
    private function clearFailures(string $normalised, string $address): void
    {
        RateLimiter::clear($this->pairKey($normalised, $address));
        RateLimiter::clear($this->identifierKey($normalised));
    }

    /**
     * Cache key for the identifier limit. The identifier is an HMAC, not plaintext.
     */
    private function identifierKey(string $normalised): string
    {
        return 'login-identifier:'.$this->identifierHmac($normalised);
    }

    /**
     * Cache key for the address-plus-identifier limit.
     */
    private function pairKey(string $normalised, string $address): string
    {
        return 'login-pair:'.$this->identifierHmac($normalised).':'.$address;
    }

    /**
     * HMAC of the normalised identifier, keyed with the application key.
     */
    private function identifierHmac(string $normalised): string
    {
        return hash_hmac('sha256', $normalised, (string) config('app.key'));
    }

    /**
     * One bcrypt hash at the application's current cost.
     */
    private function dummyHash(): string
    {
        return self::$dummy_hash ??= Hash::make('portal-login-dummy');
    }
}
