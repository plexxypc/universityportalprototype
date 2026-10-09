<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign out a user who is no longer allowed to stay signed in.
 *
 * Suspended and deactivated are treated the same. The redirect does not
 * say which one it was.
 */
class EnsureActive
{
    /**
     * Sign the user out when their status is not Active.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->status !== UserStatus::Active) {
            app(AuthService::class)->logout($request);

            return redirect()->route('login');
        }

        return $next($request);
    }
}
