<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * The only authenticator.
 *
 * The portal login and the staff panel both come through here.
 * Filament does not authenticate on its own.
 */
final class AuthService
{
    /**
     * Shown for every failed sign-in. The text does not say why.
     */
    public const string FAILURE_MESSAGE = 'Invalid credentials.';

    /**
     * Bcrypt hash used when there is no user hash to check.
     */
    private static ?string $dummy_hash = null;

    /**
     * Sign in, or fail with no reason.
     *
     * The password is checked once on every attempt. A missing user is
     * checked against a dummy hash so that path still does the slow work.
     */
    public function attempt(Request $request, string $identifier, string $password): LoginResult
    {
        $normalised = $this->normaliseIdentifier($identifier);
        $user = $this->findUser($normalised);
        $password_matches = $this->passwordMatches($user, $password);

        if (! $user instanceof User || ! $password_matches || ! $this->maySignIn($user)) {
            return LoginResult::failed();
        }

        if (! $user->hasStaffRole() && ! $user->hasRole(Role::Student)) {
            $this->discardSignedInSession($request, $user);

            return LoginResult::failed();
        }

        $this->completeSignIn($request, $user);

        return LoginResult::succeeded($this->homePath($user));
    }

    /**
     * Where a signed-in user continues.
     *
     * Any staff role wins, including when a students row also exists.
     * A student-only user goes to the student placeholder.
     */
    public function homePath(User $user): string
    {
        if ($user->hasStaffRole()) {
            return '/staff';
        }

        return '/student';
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
     * One bcrypt hash at the application's current cost.
     */
    private function dummyHash(): string
    {
        return self::$dummy_hash ??= Hash::make('portal-login-dummy');
    }
}
