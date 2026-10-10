<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function __construct(private readonly AuthService $auth) {}

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
     * The session is regenerated first. Other sessions are then ended.
     * The current session's password hash is refreshed last.
     */
    public function change(Request $request, User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'must_change_password' => false,
            'temp_password_expires_at' => null,
        ])->save();

        $request->session()->regenerate();
        $this->auth->signOutOtherSessions($request, $user);
        $this->refreshPasswordHash($request, $user);
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
