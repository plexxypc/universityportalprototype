<?php

declare(strict_types=1);

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\ImportDownloadController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\RegistrationSlipController;
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
    Route::get('/staff/downloads/documents/{document}', DocumentDownloadController::class)->name('staff.downloads.document');
    Route::get('/staff/downloads/registrations/{registration}', [RegistrationSlipController::class, 'staff'])
        ->whereNumber('registration')
        ->name('staff.downloads.registration');
    Route::get('/staff/downloads/imports/{batch}', [ImportDownloadController::class, 'failures'])->whereNumber('batch')->name('staff.downloads.import-failures');
    Route::get('/staff/downloads/import-template.csv', [ImportDownloadController::class, 'templateCsv'])->name('staff.downloads.import-template-csv');
    Route::get('/staff/downloads/import-template.xlsx', [ImportDownloadController::class, 'templateXlsx'])->name('staff.downloads.import-template-xlsx');
});

Route::redirect('/staff/login', '/login')->name('staff.login');
