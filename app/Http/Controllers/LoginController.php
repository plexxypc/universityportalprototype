<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AuthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Portal login. The action stays thin and calls AuthService.
 */
class LoginController extends Controller
{
    /**
     * Show the login form.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Attempt sign-in and redirect, or return to the form.
     *
     * The password is not flashed. Only the identifier is kept.
     */
    public function store(LoginRequest $request, AuthService $auth): RedirectResponse
    {
        $result = $auth->attempt(
            $request,
            (string) $request->validated('identifier'),
            (string) $request->validated('password'),
        );

        if (! $result->succeeded || $result->redirect_to === null) {
            return redirect()
                ->route('login')
                ->withInput($request->only('identifier'))
                ->withErrors(['identifier' => AuthService::FAILURE_MESSAGE]);
        }

        return redirect()->to($result->redirect_to);
    }
}
