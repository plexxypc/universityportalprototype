<?php

declare(strict_types=1);

use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoutController;
use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Support\DesignPreviewTable;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', EnsureActive::class, EnsurePasswordChanged::class])->group(function (): void {
    Route::view('/change-password', 'auth.change-password')->name('password.edit');
    Route::post('/logout', LogoutController::class)->name('logout');
});

if (app()->environment('local')) {
    Route::get('/design-preview', function () {
        return view('design-preview', [
            'preview_table' => DesignPreviewTable::fromQuery(request()->query()),
        ]);
    })->name('design-preview');

    Route::view('/design-preview/student', 'design-preview-student')->name('design-preview.student');
}
