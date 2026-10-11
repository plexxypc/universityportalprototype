<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the reset form.
 *
 * Portal password rules run in PasswordResetService, after the throttle
 * check, so a locked submit stays on the friendly failure message.
 */
class ResetPasswordRequest extends FormRequest
{
    /**
     * The form is public.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Identifier, new password, and confirmation.
     *
     * The token stays in the route. It is not a field.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'confirmed'],
        ];
    }
}
