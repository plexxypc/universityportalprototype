<?php

declare(strict_types=1);

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\Student\AnnouncementController;
use App\Http\Controllers\Student\CourseRegistrationController;
use App\Http\Controllers\Student\DocumentController;
use App\Http\Controllers\Student\HomeController;
use App\Http\Controllers\Student\MoreController;
use App\Http\Controllers\Student\NotificationController;
use App\Http\Controllers\Student\ProfileController;
use App\Http\Controllers\Student\SettingsController;
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
    Route::get('/student', HomeController::class)->name('student.home');
    Route::get('/student/more', MoreController::class)->name('student.more');
    Route::get('/student/profile', ProfileController::class)->name('student.profile');
    Route::get('/student/documents', DocumentController::class)->name('student.documents');
    Route::get('/student/course-registration', CourseRegistrationController::class)->name('student.course-registration');
    Route::get('/student/documents/{document}', DocumentDownloadController::class)
        ->whereNumber('document')
        ->name('student.documents.download');
    Route::get('/student/announcements', AnnouncementController::class)->name('student.announcements');
    Route::get('/student/settings', SettingsController::class)->name('student.settings');

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
