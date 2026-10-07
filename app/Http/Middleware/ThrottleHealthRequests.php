<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Limit /health without touching MySQL.
 *
 * The default cache store is the database. Using it here made a missing
 * driver or a slow connection into an HTML error page before the health
 * check could answer. TrustProxies has already run, so ip() is the
 * forwarded client when the load balancer sent X-Forwarded-For.
 */
final class ThrottleHealthRequests
{
    /**
     * Allow the configured number of probes per minute for this client.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $max_attempts = (int) config('portal.health.rate_limit_per_minute');

        if ($max_attempts < 1) {
            $max_attempts = 60;
        }

        $key = 'health'.(string) $request->ip();

        try {
            $limiter = new RateLimiter(Cache::store($this->store()));

            if ($limiter->tooManyAttempts($key, $max_attempts)) {
                return response()
                    ->json(['status' => 'degraded'], 429)
                    ->header('Cache-Control', 'no-store, private')
                    ->header('Retry-After', (string) $limiter->availableIn($key));
            }

            $limiter->hit($key, 60);
        } catch (Throwable) {
            return $next($request);
        }

        return $next($request);
    }

    /**
     * Cache store for this limiter. Tests use array; the app uses file.
     */
    private function store(): string
    {
        $store = config('portal.health.rate_limit_store');

        return is_string($store) && $store !== '' ? $store : 'file';
    }
}
