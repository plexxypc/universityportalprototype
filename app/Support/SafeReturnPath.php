<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/**
 * Same-site path a signed-in user may return to.
 *
 * External URLs, protocol-relative URLs, and non-page paths are discarded.
 */
final class SafeReturnPath
{
    /**
     * Whether this request may store a post-login return path.
     *
     * Only a plain GET for an HTML page qualifies. Livewire updates, other
     * POST requests, and asset or probe paths do not.
     */
    public static function shouldRemember(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($request->headers->has('X-Livewire') || $request->expectsJson()) {
            return false;
        }

        return self::isPagePath('/'.ltrim($request->path(), '/'));
    }

    /**
     * Path from this request, when it is safe to store.
     */
    public static function fromRequest(Request $request): ?string
    {
        if (! self::shouldRemember($request)) {
            return null;
        }

        $path = '/'.ltrim($request->path(), '/');
        $query = $request->getQueryString();
        $candidate = is_string($query) && $query !== '' ? $path.'?'.$query : $path;

        return self::accept($candidate);
    }

    /**
     * A same-site page path, or null when the candidate must be ignored.
     */
    public static function accept(?string $candidate): ?string
    {
        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        if ($candidate === '' || preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
            return null;
        }

        if (str_contains($candidate, '\\')) {
            return null;
        }

        if (str_contains($candidate, '://') || str_starts_with($candidate, '//')) {
            $candidate = self::pathFromAbsolute($candidate);

            if ($candidate === null) {
                return null;
            }
        }

        $parts = parse_url($candidate);

        if (! is_array($parts) || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        $decoded = rawurldecode($path);

        if (
            str_contains($decoded, '\\')
            || str_contains($decoded, '//')
            || str_contains($decoded, '://')
            || ! str_starts_with($decoded, '/')
        ) {
            return null;
        }

        if (! self::isPagePath($path) || self::isAuthPath($path)) {
            return null;
        }

        $query = $parts['query'] ?? null;

        if (! is_string($query) || $query === '') {
            return $path;
        }

        if (str_contains($query, '://') || str_contains($query, '//') || str_contains($query, '\\')) {
            return null;
        }

        return $path.'?'.$query;
    }

    /**
     * Whether this path is a document the user can return to.
     */
    public static function isPagePath(string $path): bool
    {
        $path = '/'.ltrim($path, '/');

        if ($path === '/up' || $path === '/health' || $path === '/livewire/update') {
            return false;
        }

        if (str_starts_with($path, '/livewire/')) {
            return false;
        }

        $prefix = EndpointResolver::prefix();

        if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
            return false;
        }

        return preg_match('/\.[A-Za-z0-9]{1,8}$/', $path) !== 1;
    }

    /**
     * Drop the origin when it is this application's host and port.
     */
    private static function pathFromAbsolute(string $candidate): ?string
    {
        if (str_starts_with($candidate, '//')) {
            return null;
        }

        $parts = parse_url($candidate);

        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $app = parse_url((string) config('app.url'));

        if (! is_array($app) || ! isset($app['host'])) {
            return null;
        }

        if (strcasecmp((string) $parts['host'], (string) $app['host']) !== 0) {
            return null;
        }

        $app_scheme = strtolower((string) ($app['scheme'] ?? 'http'));
        $expected_port = isset($app['port'])
            ? (int) $app['port']
            : ($app_scheme === 'https' ? 443 : 80);
        $candidate_port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        if ($candidate_port !== $expected_port) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $query = isset($parts['query']) && $parts['query'] !== ''
            ? '?'.$parts['query']
            : '';

        return $path.$query;
    }

    /**
     * Login and logout must not become the return target.
     */
    private static function isAuthPath(string $path): bool
    {
        return in_array($path, ['/login', '/logout', '/staff/logout'], true);
    }
}
