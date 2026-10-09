<?php

declare(strict_types=1);

use App\Http\Controllers\PingController;
use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePortalArea;
use Illuminate\Support\Facades\Route;

/*
 * Non-Filament staff routes stay under /staff/downloads.
 * Filament owns /staff and its page slugs (/staff/login, resources, pages).
 * Do not add a Filament page or resource whose slug is "downloads".
 */
Route::middleware([
    'auth',
    EnsureActive::class,
    EnsurePasswordChanged::class,
    EnsurePortalArea::class.':staff',
])->group(function (): void {
    Route::get('/staff/downloads/ping', [PingController::class, 'staff_downloads'])->name('staff.downloads.ping');
});

Route::redirect('/staff/login', '/login')->name('staff.login');
