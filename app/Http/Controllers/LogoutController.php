<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Portal logout. The action stays thin and calls AuthService.
 */
class LogoutController extends Controller
{
    /**
     * End the session and return to the login page.
     */
    public function __invoke(Request $request, AuthService $auth): RedirectResponse
    {
        $auth->logout($request);

        return redirect()->route('login');
    }
}
