<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the forgot-password form.
 *
 * The same response is used whether or not an account exists. This request
 * only checks the shape of the identifier.
 */
class ForgotPasswordRequest extends FormRequest
{
    /**
     * The form is public.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The identifier is an email or a matric number.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
        ];
    }
}
