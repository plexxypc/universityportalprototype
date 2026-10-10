<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep portal pages out of the browser cache.
 *
 * After sign-out, the Back button must request the page again. A stored
 * copy would show a private page with no session behind it.
 */
class NoStoreResponse
{
    /**
     * Mark the response so the browser does not store it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
