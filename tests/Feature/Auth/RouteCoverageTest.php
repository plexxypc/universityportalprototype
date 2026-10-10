<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateStaffPanel;
use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePortalArea;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;

/**
 * Public routes, keyed by route name.
 *
 * A route on this list does not need auth or area middleware. The reason
 * says why. A name that is not registered fails the allow-list test.
 *
 * @return array<string, string>
 */
function route_coverage_named_public_routes(): array
{
    return [
        'login' => 'Portal login page.',
        'login.store' => 'Portal login action.',
        'staff.login' => 'The staff panel login redirects to the portal login.',
        'health' => 'Health probe. It does not start a session.',
        'webhooks.ping' => 'Provider notification ping. It sits outside the auth groups.',
        'default-livewire.update' => 'Persistent middleware from the page protects this Livewire update.',
        'livewire.upload-file' => 'Persistent middleware from the page protects this Livewire upload.',
        'livewire.preview-file' => 'Persistent middleware from the page protects this Livewire preview.',
        'storage.local' => 'Signed file route for the private local disk.',
        'storage.local.upload' => 'Signed upload route for the private local disk.',
        'filament.exports.download' => 'Filament checks the signed-in owner in the controller. This package route has no auth middleware.',
        'filament.imports.failed-rows.download' => 'Filament checks the signed-in owner in the controller. This package route has no auth middleware.',
    ];
}

/**
 * Change-password and logout stay signed in, and they do not require an area.
 *
 * @return list<string>
 */
function route_coverage_authenticated_without_area(): array
{
    return [
        'password.edit',
        'password.update',
        'logout',
    ];
}

/**
 * Name and URI for a failure message.
 */
function route_coverage_label(RegisteredRoute $route): string
{
    $name = $route->getName();
    $methods = implode('|', array_values(array_diff($route->methods(), ['HEAD'])));
    $uri = $route->uri();

    if (is_string($name) && $name !== '') {
        return $name.' ('.$methods.' '.$uri.')';
    }

    return $methods.' '.$uri;
}

/**
 * Whether this middleware entry is the given class, with or without a parameter.
 */
function route_coverage_middleware_is(string $entry, string $class): bool
{
    return $entry === $class || str_starts_with($entry, $class.':');
}

/**
 * Whether the stack authenticates: portal auth, or the staff panel gate.
 *
 * @param  list<string>  $middleware
 */
function route_coverage_has_auth(array $middleware): bool
{
    foreach ($middleware as $entry) {
        if ($entry === 'auth' || str_starts_with($entry, 'auth:')) {
            return true;
        }

        if (route_coverage_middleware_is($entry, Authenticate::class)) {
            return true;
        }

        if (route_coverage_middleware_is($entry, AuthenticateStaffPanel::class)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether the stack chooses the student area or the staff area.
 *
 * @param  list<string>  $middleware
 */
function route_coverage_has_area(array $middleware): bool
{
    foreach ($middleware as $entry) {
        if (route_coverage_middleware_is($entry, EnsurePortalArea::class)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether the stack contains this middleware class.
 *
 * @param  list<string>  $middleware
 */
function route_coverage_has_class(array $middleware, string $class): bool
{
    foreach ($middleware as $entry) {
        if (route_coverage_middleware_is($entry, $class)) {
            return true;
        }
    }

    return false;
}

/**
 * Reason this unnamed route is public, or null when it is not on that list.
 */
function route_coverage_unnamed_public_reason(RegisteredRoute $route): ?string
{
    $uri = $route->uri();

    if ($uri === '/') {
        return 'The public home page.';
    }

    if ($uri === 'up') {
        return 'Framework health probe.';
    }

    if (preg_match(
        '#^livewire-[0-9a-f]+/(livewire(?:\.min)?\.js|livewire\.min\.js\.map|livewire\.csp\.min\.js\.map|css/\{component\}\.css|css/\{component\}\.global\.css|js/\{component\}\.js)$#',
        $uri,
    ) === 1) {
        return 'Livewire script and style assets.';
    }

    return null;
}

/**
 * Routes that are missing the middleware this phase requires.
 *
 * @return list<string>
 */
function route_coverage_failures(): array
{
    $public = route_coverage_named_public_routes();
    $session_only = route_coverage_authenticated_without_area();
    $failures = [];

    foreach (Route::getRoutes() as $route) {
        if (! $route instanceof RegisteredRoute) {
            continue;
        }

        $name = $route->getName();
        $named = is_string($name) && $name !== '';
        $label = route_coverage_label($route);
        $middleware = array_map(strval(...), $route->gatherMiddleware());

        if ($named && array_key_exists($name, $public)) {
            continue;
        }

        if (route_coverage_unnamed_public_reason($route) !== null && ! $named) {
            continue;
        }

        if ($named && in_array($name, $session_only, true)) {
            if (! route_coverage_has_auth($middleware)
                || ! route_coverage_has_class($middleware, EnsureActive::class)
                || ! route_coverage_has_class($middleware, EnsurePasswordChanged::class)) {
                $failures[] = $label.' is missing auth, EnsureActive, or EnsurePasswordChanged.';
            }

            continue;
        }

        if (! route_coverage_has_auth($middleware) || ! route_coverage_has_area($middleware)) {
            $failures[] = $label.' is not on the public allow-list and is missing auth or area middleware.';
        }
    }

    return $failures;
}

it('requires auth and an area on every route that is not public', function () {
    $failures = route_coverage_failures();

    expect($failures)->toBe([]);
});

it('points the public allow-list at routes that are registered', function () {
    foreach (array_keys(route_coverage_named_public_routes()) as $name) {
        expect(Route::has($name))->toBeTrue();
    }

    $uris = [];

    foreach (Route::getRoutes() as $route) {
        if ($route instanceof RegisteredRoute) {
            $uris[] = $route->uri();
        }
    }

    expect($uris)->toContain('/')
        ->and($uris)->toContain('up')
        ->and(collect($uris)->contains(
            fn (string $uri): bool => str_contains($uri, '/livewire.min.js') || str_contains($uri, '/livewire.js'),
        ))->toBeTrue();
});

it('keeps the design preview routes inside the local guard', function () {
    expect(Route::has('design-preview'))->toBeFalse()
        ->and(Route::has('design-preview.student'))->toBeFalse()
        ->and(Route::has('design-preview.emails'))->toBeFalse();

    $source = file_get_contents(base_path('routes/web.php'));

    expect($source)->toBeString();

    $source = (string) $source;
    $guard = strpos($source, "environment('local')");
    $preview = strpos($source, "'/design-preview'");
    $student_preview = strpos($source, "'/design-preview/student'");
    $email_preview = strpos($source, "'/design-preview/emails'");

    expect($guard)->toBeInt()
        ->and($preview)->toBeInt()
        ->and($student_preview)->toBeInt()
        ->and($email_preview)->toBeInt()
        ->and($guard < $preview)->toBeTrue()
        ->and($guard < $student_preview)->toBeTrue()
        ->and($guard < $email_preview)->toBeTrue();
});
