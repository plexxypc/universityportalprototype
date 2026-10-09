<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the portal login form.
 *
 * This request does not decide who may sign in. AuthService does.
 */
class LoginRequest extends FormRequest
{
    /**
     * The login form is public.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Identifier and password must be present and bounded.
     *
     * The password cap is 72 characters, which is the bcrypt input limit.
     * The message for a bad shape is a validation error. A well-formed
     * wrong password is handled later, with one generic failure.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
        ];
    }
}
