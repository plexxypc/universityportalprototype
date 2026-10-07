<?php

declare(strict_types=1);

namespace App\Http\Controllers;

/**
 * Plain responses for the route-file placeholders.
 *
 * Actions are methods so `route:cache` can serialise them.
 */
final class PingController extends Controller
{
    /**
     * Confirm the student route file is loaded.
     */
    public function student(): string
    {
        return 'student routes ok';
    }

    /**
     * Confirm the non-Filament staff route file is loaded.
     */
    public function staff_downloads(): string
    {
        return 'staff routes ok';
    }

    /**
     * Confirm the webhook route file is loaded.
     */
    public function webhook(): string
    {
        return 'webhook routes ok';
    }
}
