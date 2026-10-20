<?php

declare(strict_types=1);

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\RegistrationSlipController;
use App\Http\Controllers\Student\AnnouncementController;
use App\Http\Controllers\Student\CourseRegistrationController;
use App\Http\Controllers\Student\DemoPaymentController;
use App\Http\Controllers\Student\DocumentController;
use App\Http\Controllers\Student\FeesController;
use App\Http\Controllers\Student\HomeController;
use App\Http\Controllers\Student\MoreController;
use App\Http\Controllers\Student\NotificationController;
use App\Http\Controllers\Student\PaymentStatusController;
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
    Route::get('/student/fees', [FeesController::class, 'index'])->name('student.fees');
    Route::get('/student/fees/{invoice}', [FeesController::class, 'show'])
        ->whereNumber('invoice')
        ->name('student.fees.show');
    Route::post('/student/fees/{invoice}/pay', [FeesController::class, 'pay'])
        ->whereNumber('invoice')
        ->name('student.fees.pay');
    Route::get('/student/payments/demo/{reference}', [DemoPaymentController::class, 'show'])
        ->where('reference', '[A-Za-z0-9]+')
        ->name('student.payments.demo');
    Route::post('/student/payments/demo/{reference}', [DemoPaymentController::class, 'store'])
        ->where('reference', '[A-Za-z0-9]+')
        ->name('student.payments.demo.store');
    Route::get('/student/payments/{reference}', [PaymentStatusController::class, 'show'])
        ->where('reference', '[A-Za-z0-9]+')
        ->name('student.payments.show');
    Route::post('/student/payments/{reference}/check', [PaymentStatusController::class, 'check'])
        ->where('reference', '[A-Za-z0-9]+')
        ->name('student.payments.check');
    Route::get('/student/course-registration/slip', [RegistrationSlipController::class, 'student'])
        ->name('student.course-registration.slip');
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
