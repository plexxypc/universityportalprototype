<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\HealthService;
use Illuminate\Http\JsonResponse;

/**
 * Public health payload. The service decides the status and the status code.
 */
final class HealthController extends Controller
{
    /**
     * Return coarse status and keep the response out of caches.
     */
    public function show(HealthService $health): JsonResponse
    {
        $snapshot = $health->snapshot();

        // Symfony adds "private" unless it is already present. Both directives
        // tell caches not to store the probe.
        return response()
            ->json($snapshot, $health->httpStatus($snapshot))
            ->header('Cache-Control', 'no-store, private');
    }
}
