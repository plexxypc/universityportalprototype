<?php

declare(strict_types=1);

use App\Http\Controllers\PingController;
use App\Http\Controllers\Student\NotificationController;
use App\Http\Middleware\EnsureActive;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePortalArea;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Auth\StudentAreaProbePage;

Route::middleware([
    'auth',
    EnsureActive::class,
    EnsurePasswordChanged::class,
    EnsurePortalArea::class.':student',
])->group(function (): void {
    Route::view('/student', 'student.home')->name('student.home');

    Route::get('/student/notifications', [NotificationController::class, 'index'])
        ->name('student.notifications');
    Route::post('/student/notifications/read-all', [NotificationController::class, 'markAll'])
        ->name('student.notifications.read-all');
    Route::post('/student/notifications/{notification}/read', [NotificationController::class, 'markRead'])
        ->whereNumber('notification')
        ->name('student.notifications.read');

    Route::get('/student/ping', [PingController::class, 'student'])->name('student.ping');

    if (app()->runningUnitTests()) {
        Route::get('/student/livewire-probe', StudentAreaProbePage::class)
            ->name('student.livewire-probe');
    }
});
