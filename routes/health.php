<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Middleware\ThrottleHealthRequests;
use Illuminate\Support\Facades\Route;

/*
 * Loaded without the web middleware group, so a probe does not start a
 * session or set a cookie. The heartbeat shows that the scheduler ran
 * recently. It does not show that the queue worker is consuming jobs.
 */
Route::get('/health', [HealthController::class, 'show'])
    ->middleware(ThrottleHealthRequests::class)
    ->name('health');
