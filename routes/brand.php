<?php

declare(strict_types=1);

use App\Http\Controllers\InstitutionLogoController;
use Illuminate\Support\Facades\Route;

/*
 * Loaded without the web middleware group, so the response is not marked
 * no-store and the logo request does not start a session.
 */
Route::get('/institution/logo', [InstitutionLogoController::class, 'show'])
    ->name('institution.logo');
