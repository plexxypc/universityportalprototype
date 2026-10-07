<?php

declare(strict_types=1);

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

/*
 * Non-Filament staff routes stay under /staff/downloads.
 * Filament owns /staff and its page slugs (/staff/login, resources, pages).
 * Do not add a Filament page or resource whose slug is "downloads".
 */
Route::get('/staff/downloads/ping', [PingController::class, 'staff_downloads'])->name('staff.downloads.ping');
