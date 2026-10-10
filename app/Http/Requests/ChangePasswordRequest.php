<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\PortalPassword;
use App\Services\PasswordService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Validator;

/**
 * Validate a change-password submission for the signed-in user.
 *
 * The current-password check and the lockout use one field error.
 */
class ChangePasswordRequest extends FormRequest
{
    /**
     * Only a signed-in user may post this form.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * Current password, new password, and confirmation.
     *
     * There is no user id. The account is the authenticated user.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $password_rules = ['required', 'string', 'confirmed'];

        if ($user instanceof User) {
            $password_rules[] = new PortalPassword($user);
        }

        return [
            'current_password' => ['required', 'string'],
            'password' => $password_rules,
        ];
    }

    /**
     * Check the current password once, including while the page is locked.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if (! $user instanceof User) {
                return;
            }

            $passwords = app(PasswordService::class);
            $locked = RateLimiter::tooManyAttempts(
                $passwords->throttleKey($user),
                PasswordService::MAX_ATTEMPTS,
            );
            $matches = Hash::check(
                (string) $this->input('current_password', ''),
                (string) $user->getAuthPassword(),
            );

            if ($locked || ! $matches) {
                if (! $locked) {
                    RateLimiter::hit($passwords->throttleKey($user), PasswordService::DECAY_SECONDS);
                }

                $validator->errors()->add(
                    'current_password',
                    PasswordService::CURRENT_PASSWORD_ERROR,
                );
            }
        });
    }
}
