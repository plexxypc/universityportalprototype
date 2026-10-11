<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmailTemplate;
use App\Enums\UserStatus;
use App\Exceptions\PasswordResetRejected;
use App\Jobs\IssuePasswordResetLink;
use App\Models\User;
use App\Rules\PortalPassword;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Request and complete a password reset.
 *
 * The request form answers before the account lookup. The reset form
 * checks the throttle first, then the password rules, then the token.
 */
final class PasswordResetService
{
    /**
     * Remember the authenticator, the audit helper, and the outbox.
     */
    public function __construct(
        private readonly AuthService $auth,
        private readonly Audit $audits,
        private readonly MailService $mail,
    ) {}

    public const string REQUEST_MESSAGE = 'If an account matches that identifier, we have sent a reset link.';

    public const string FAILURE_MESSAGE = 'This reset link is invalid or has expired.';

    public const string SUCCESS_MESSAGE = 'Your password has been reset. Sign in to continue.';

    public const string PASSWORD_MESSAGE = 'Choose a different password.';

    /**
     * Same counts as sign-in. The keys are separate, so a reset does not lock sign-in.
     */
    private const int PAIR_MAX_ATTEMPTS = 5;

    private const int PAIR_DECAY_SECONDS = 60;

    private const int IDENTIFIER_MAX_ATTEMPTS = 10;

    private const int IDENTIFIER_DECAY_SECONDS = 900;

    /**
     * Throttle, then schedule the lookup and the email after the response.
     *
     * A locked identifier does not schedule that work. The caller still
     * shows REQUEST_MESSAGE.
     */
    public function requestLink(Request $request, string $identifier): void
    {
        $normalised = $this->auth->normaliseIdentifier($identifier);
        $address = $this->auth->clientAddress($request);
        $pair_key = $this->requestPairKey($normalised, $address);
        $identifier_key = $this->requestIdentifierKey($normalised);

        if ($this->blocked($pair_key, $identifier_key)) {
            return;
        }

        $this->hit($pair_key, $identifier_key);

        dispatch(new IssuePasswordResetLink($normalised))->afterResponse();
    }

    /**
     * Create a token for an Active user, or do the dummy hash work.
     *
     * Runs after the response. A missing, deactivated, or suspended account
     * gets no email and no audit row.
     */
    public function issue(string $normalised): void
    {
        $user = $this->auth->findUser($normalised);

        if (! $user instanceof User || $user->status !== UserStatus::Active) {
            $this->auth->checkDummyPassword();

            return;
        }

        $token = Password::broker()->createToken($user);
        $user->sendPasswordResetNotification($token);
    }

    /**
     * Store a new password when the token matches an Active user.
     *
     * The password, the session delete, the token delete, and the audit row
     * share one transaction. The person is not signed in.
     */
    public function complete(
        Request $request,
        #[\SensitiveParameter] string $token,
        string $identifier,
        #[\SensitiveParameter] string $password,
    ): PasswordResetResult {
        $normalised = $this->auth->normaliseIdentifier($identifier);
        $address = $this->auth->clientAddress($request);
        $pair_key = $this->submitPairKey($normalised, $address);
        $identifier_key = $this->submitIdentifierKey($normalised);

        if ($this->blocked($pair_key, $identifier_key)) {
            return PasswordResetResult::failed();
        }

        $this->hit($pair_key, $identifier_key);

        if (! $this->passwordShapeIsAllowed($password, $normalised)) {
            return PasswordResetResult::passwordRejected();
        }

        $user = $this->auth->findUser($normalised);

        if (! $user instanceof User || $user->status !== UserStatus::Active) {
            $this->auth->checkDummyPassword();

            return PasswordResetResult::failed();
        }

        DB::beginTransaction();

        try {
            $status = Password::reset(
                [
                    'email' => $user->email,
                    'password' => $password,
                    'password_confirmation' => $password,
                    'token' => $token,
                ],
                function (mixed $account) use ($request, $password): void {
                    if (! $account instanceof User || ! $this->passwordIsAcceptable($account, $password)) {
                        throw new PasswordResetRejected;
                    }

                    $this->applyReset($request, $account, $password);
                },
            );
        } catch (PasswordResetRejected) {
            DB::rollBack();

            return PasswordResetResult::passwordRejected();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if ($status !== Password::PASSWORD_RESET) {
            DB::rollBack();

            return PasswordResetResult::failed();
        }

        DB::commit();
        $this->endSignedInSession($request, $user);
        $this->auth->clearSignInLockout($user, $address);
        $this->mail->supersedeUnsent($user->id, EmailTemplate::PasswordReset->value);

        return PasswordResetResult::reset();
    }

    /**
     * Whether either reset limit is already spent.
     */
    private function blocked(string $pair_key, string $identifier_key): bool
    {
        return RateLimiter::tooManyAttempts($pair_key, self::PAIR_MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts($identifier_key, self::IDENTIFIER_MAX_ATTEMPTS);
    }

    /**
     * Count one request against both limits.
     */
    private function hit(string $pair_key, string $identifier_key): void
    {
        RateLimiter::hit($pair_key, self::PAIR_DECAY_SECONDS);
        RateLimiter::hit($identifier_key, self::IDENTIFIER_DECAY_SECONDS);
    }

    /**
     * Length, character, common-password, and typed-identifier checks.
     *
     * These do not need an account, so a weak password looks the same
     * whether or not one exists.
     */
    private function passwordShapeIsAllowed(string $password, string $normalised): bool
    {
        $email = str_contains($normalised, '@') ? $normalised : '';

        if (! PortalPassword::allows($password, $email)) {
            return false;
        }

        if ($email === '' && $normalised !== '' && str_contains(mb_strtolower($password), mb_strtolower($normalised))) {
            return false;
        }

        return true;
    }

    /**
     * Full portal rules, including the current password and the matric number.
     */
    private function passwordIsAcceptable(User $user, #[\SensitiveParameter] string $password): bool
    {
        return (new PortalPassword($user))->accepts($password);
    }

    /**
     * Store the hash, end every session, drop reset tokens, and audit once.
     */
    private function applyReset(Request $request, User $user, #[\SensitiveParameter] string $password): void
    {
        $forced = (bool) $user->must_change_password;
        $user_id = $user->id;
        $ip = $this->audits->ipFromRequest($request);

        $user->forceFill([
            'password' => $password,
            'must_change_password' => false,
            'temp_password_expires_at' => null,
            'remember_token' => Str::random(60),
        ])->save();

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();

        DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
            ->where('email', $user->getEmailForPasswordReset())
            ->delete();

        $this->audits->record(
            $user_id,
            AuditService::ACTION_PASSWORD_RESET,
            AuditService::ENTITY_USERS,
            $user_id,
            ['must_change_password' => $forced],
            ['must_change_password' => false],
            $ip,
        );
    }

    /**
     * Drop the portal session when this browser was signed in as that user.
     *
     * AuthService::logout would write a second audit row. This path does not.
     */
    private function endSignedInSession(Request $request, User $user): void
    {
        if ($request->user()?->getAuthIdentifier() !== $user->getAuthIdentifier()) {
            return;
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    /**
     * Cache key for the request form, identifier plus socket address.
     */
    private function requestPairKey(string $normalised, string $address): string
    {
        return 'password-reset-request-pair:'.$this->auth->identifierHmac($normalised).':'.$address;
    }

    /**
     * Cache key for the request form, identifier only.
     */
    private function requestIdentifierKey(string $normalised): string
    {
        return 'password-reset-request-identifier:'.$this->auth->identifierHmac($normalised);
    }

    /**
     * Cache key for the reset form, identifier plus socket address.
     */
    private function submitPairKey(string $normalised, string $address): string
    {
        return 'password-reset-submit-pair:'.$this->auth->identifierHmac($normalised).':'.$address;
    }

    /**
     * Cache key for the reset form, identifier only.
     */
    private function submitIdentifierKey(string $normalised): string
    {
        return 'password-reset-submit-identifier:'.$this->auth->identifierHmac($normalised);
    }
}
