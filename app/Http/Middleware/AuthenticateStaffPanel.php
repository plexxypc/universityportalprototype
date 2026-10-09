<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;

/**
 * Send an unsigned staff-panel request to the portal login.
 *
 * Filament's login page is not registered. This class is the panel gate,
 * and the panel marks it persistent so a Livewire update inside /staff
 * is rejected when the session is gone.
 */
class AuthenticateStaffPanel extends FilamentAuthenticate
{
    /**
     * Portal login path for a guest.
     */
    protected function redirectTo($request): ?string
    {
        return route('login');
    }
}
