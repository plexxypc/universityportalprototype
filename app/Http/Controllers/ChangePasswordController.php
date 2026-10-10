<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ChangePasswordRequest;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PasswordService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Forced and voluntary password changes. The action stays thin.
 */
class ChangePasswordController extends Controller
{
    /**
     * Show the change-password form for the signed-in user.
     */
    public function create(Request $request, AuthService $auth): View
    {
        $user = $request->user();

        return view('auth.change-password', [
            'home' => $user instanceof User ? $auth->homePath($user) : null,
            'must_change_password' => $user instanceof User && $user->must_change_password,
        ]);
    }

    /**
     * Store the new password and return the user to their home page.
     */
    public function store(
        ChangePasswordRequest $request,
        PasswordService $passwords,
        AuthService $auth,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $passwords->change($request, $user, (string) $request->validated('password'));

        $home = $auth->homePath($user);

        if ($home === null) {
            $auth->logout($request);

            return redirect()->route('login');
        }

        return redirect($home)->with('toast', 'Your password has been changed.');
    }
}
