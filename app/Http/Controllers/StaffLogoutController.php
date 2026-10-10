<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Staff panel logout. The action stays thin and calls AuthService.
 */
class StaffLogoutController extends Controller
{
    /**
     * End the session and return to the portal login page.
     */
    public function __invoke(Request $request, AuthService $auth): RedirectResponse
    {
        $auth->logout($request);

        return redirect()->route('login');
    }
}
