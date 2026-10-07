<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Loaded without the web middleware group, so these routes are not CSRF-protected.
 * Real provider notifications will live under /payments/notify/{provider}.
 */
Route::get('/payments/notify/ping', static function (): string {
    return 'webhook routes ok';
})->name('webhooks.ping');
