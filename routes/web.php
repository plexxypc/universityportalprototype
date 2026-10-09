<?php

declare(strict_types=1);

use App\Http\Controllers\LoginController;
use App\Support\DesignPreviewTable;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

if (app()->environment('local')) {
    Route::get('/design-preview', function () {
        return view('design-preview', [
            'preview_table' => DesignPreviewTable::fromQuery(request()->query()),
        ]);
    })->name('design-preview');

    Route::view('/design-preview/student', 'design-preview-student')->name('design-preview.student');
}
