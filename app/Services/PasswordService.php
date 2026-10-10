<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Change the signed-in user's password.
 *
 * The portal page and the staff panel both use this method.
 */
final class PasswordService
{
    /**
     * Remember the authenticator that ends the other sessions.
     */
    public function __construct(
        private readonly AuthService $auth,
        private readonly Audit $audits,
    ) {}

    /**
     * Failed attempts on the change-password page for one user.
     */
    public const int MAX_ATTEMPTS = 5;

    public const int DECAY_SECONDS = 60;

    /**
     * Shown for a wrong current password and for a lockout. The text does not say which.
     */
    public const string CURRENT_PASSWORD_ERROR = 'The current password is incorrect.';

    /**
     * Store the new password and keep only this browser signed in.
     *
     * The password change and the audit row share one transaction.
     * The session is regenerated, other sessions are ended, and the
     * current session's password hash is refreshed before the audit row.
     */
    public function change(Request $request, User $user, #[\SensitiveParameter] string $password): void
    {
        $forced = $user->must_change_password;
        $user_id = $user->id;
        $ip = $this->audits->ipFromRequest($request);

        DB::transaction(function () use ($request, $user, $password, $forced, $user_id, $ip): void {
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'temp_password_expires_at' => null,
            ])->save();

            $request->session()->regenerate();
            $this->auth->signOutOtherSessions($request, $user);
            $this->refreshPasswordHash($request, $user);

            $this->audits->record(
                $user_id,
                $forced
                    ? AuditService::ACTION_PASSWORD_FORCED_CHANGE
                    : AuditService::ACTION_PASSWORD_CHANGED,
                AuditService::ENTITY_USERS,
                $user_id,
                ['must_change_password' => $forced],
                ['must_change_password' => false],
                $ip,
            );
        });

        RateLimiter::clear($this->throttleKey($user));
    }

    /**
     * Cache key for this user's change-password attempts.
     */
    public function throttleKey(User $user): string
    {
        return 'password-change:'.$user->getAuthIdentifier();
    }

    /**
     * Store the hash AuthenticateSession compares on the next request.
     */
    private function refreshPasswordHash(Request $request, User $user): void
    {
        $hash = Auth::guard()->hashPasswordForCookie((string) $user->getAuthPassword());

        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $hash);
    }
}
