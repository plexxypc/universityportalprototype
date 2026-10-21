<?php

declare(strict_types=1);

use App\Http\Controllers\PaymentNotificationController;
use App\Http\Controllers\PaymentReturnController;
use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

/*
 * Loaded without the web middleware group, so these routes are not CSRF-protected.
 * Real provider notifications will live under /payments/notify/{provider}.
 */
Route::get('/payments/notify/ping', [PingController::class, 'webhook'])->name('webhooks.ping');

Route::match(['GET', 'POST'], '/payments/return', PaymentReturnController::class)
    ->middleware('throttle:payments-return')
    ->name('payments.return');

Route::post('/payments/notify/{provider}', PaymentNotificationController::class)
    ->where('provider', '[A-Za-z0-9]+')
    ->middleware('throttle:payments-notify')
    ->name('payments.notify');
