<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
            __DIR__.'/../routes/student.php',
            __DIR__.'/../routes/staff.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (Application $application): void {
            // Provider notifications must not sit in the CSRF-protected web group.
            require $application->basePath('routes/webhooks.php');
            // Health probes must not start a session or set a cookie.
            require $application->basePath('routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (): string => route('login'));
        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();

            if (! $user instanceof User) {
                return route('login');
            }

            return app(AuthService::class)->homePath($user);
        });

        // Trust the platform proxy for scheme, host, and port so HTTPS and
        // generated URLs stay correct. Do not trust X-Forwarded-For: a client
        // can forge it, and neither Render nor App Platform publishes a
        // stable proxy range. Login limits use the socket address instead.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
        ]);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('health') || $request->expectsJson(),
        );

        // A browser asks for HTML. Keep /health on the coarse JSON payload
        // so a failure cannot render a stack trace or a host name.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('health')) {
                return null;
            }

            $version = config('portal.version');

            return response()->json([
                'status' => 'degraded',
                'database' => 'unreachable',
                'heartbeat' => 'stale',
                'mail_driver' => (string) config('mail.default'),
                'payment_provider' => (string) config('portal.payment_provider'),
                'environment' => (string) app()->environment(),
                'version' => is_string($version) && trim($version) !== '' ? trim($version) : null,
            ], 503)->header('Cache-Control', 'no-store, private');
        });
    })->create();
