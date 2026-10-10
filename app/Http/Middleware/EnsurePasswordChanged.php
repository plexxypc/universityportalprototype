<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep a forced password change on the change-password page.
 *
 * Allowed requests are the change-password page, the logout actions, the
 * Vite build directory, the favicon, and Livewire's own script files.
 * The Livewire update endpoint is not an asset.
 */
class EnsurePasswordChanged
{
    /**
     * Redirect every other request while the flag is set.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->must_change_password) {
            return $next($request);
        }

        if ($this->isAllowed($request)) {
            return $next($request);
        }

        return redirect()->route('password.edit');
    }

    /**
     * Whether this request may proceed during a forced change.
     */
    private function isAllowed(Request $request): bool
    {
        if ($request->routeIs(
            'password.edit',
            'password.update',
            'logout',
            'filament.staff.auth.logout',
        )) {
            return true;
        }

        return $this->isStaticAsset($request);
    }

    /**
     * Built files, the favicon, and the two Livewire script paths.
     *
     * The list is exact. A prefix other than the build directory, and any
     * other path under the Livewire prefix, is not an asset.
     */
    private function isStaticAsset(Request $request): bool
    {
        $path = $request->path();

        if ($path === 'favicon.ico' || $path === 'build' || str_starts_with($path, 'build/')) {
            return true;
        }

        $scripts = [
            ltrim(EndpointResolver::scriptPath(minified: false), '/'),
            ltrim(EndpointResolver::scriptPath(minified: true), '/'),
        ];

        return in_array($path, $scripts, true);
    }
}
