<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Services\PasswordResetService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Forgot-password and reset forms. The actions stay thin.
 */
class PasswordResetController extends Controller
{
    /**
     * Show the request form.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Accept a request and always answer with the same sentence.
     *
     * Account lookup and the email run after this response.
     */
    public function store(ForgotPasswordRequest $request, PasswordResetService $resets): RedirectResponse
    {
        $resets->requestLink($request, (string) $request->validated('identifier'));

        return redirect()
            ->route('password.request')
            ->with('status', PasswordResetService::REQUEST_MESSAGE);
    }

    /**
     * Show the reset form. The token is not passed to the view.
     */
    public function edit(): View
    {
        return view('auth.reset-password');
    }

    /**
     * Store a new password, or show the one friendly failure.
     */
    public function update(
        ResetPasswordRequest $request,
        #[\SensitiveParameter] string $token,
        PasswordResetService $resets,
    ): RedirectResponse {
        $result = $resets->complete(
            $request,
            $token,
            (string) $request->validated('identifier'),
            (string) $request->validated('password'),
        );

        if ($result->passwordWasRejected()) {
            return redirect()
                ->back()
                ->withInput($request->only('identifier'))
                ->withErrors(['password' => PasswordResetService::PASSWORD_MESSAGE]);
        }

        if ($result->resetSucceeded()) {
            return redirect()
                ->route('login')
                ->with('status', PasswordResetService::SUCCESS_MESSAGE);
        }

        return redirect()
            ->back()
            ->with('status', PasswordResetService::FAILURE_MESSAGE);
    }
}
