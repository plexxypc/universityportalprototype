<?php

declare(strict_types=1);

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

/*
 * Loaded without the web middleware group, so these routes are not CSRF-protected.
 * Real provider notifications will live under /payments/notify/{provider}.
 */
Route::get('/payments/notify/ping', [PingController::class, 'webhook'])->name('webhooks.ping');
