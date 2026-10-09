<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require the student area or the staff area.
 *
 * Staff roles come from the permission map. A user with neither area is
 * signed out so the redirect cannot loop.
 */
class EnsurePortalArea
{
    /**
     * Allow the area, send the user home, or sign them out.
     */
    public function handle(Request $request, Closure $next, string $area): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->allows($user, $area)) {
            return $next($request);
        }

        $auth = app(AuthService::class);
        $home = $auth->homePath($user);

        if ($home === null || $request->is(ltrim($home, '/'))) {
            $auth->logout($request);

            return redirect()->route('login');
        }

        return redirect($home);
    }

    /**
     * Whether this user may open the named area.
     */
    private function allows(User $user, string $area): bool
    {
        return match ($area) {
            'student' => $user->hasRole(Role::Student),
            'staff' => $user->hasStaffRole(),
            default => false,
        };
    }
}
