<?php

declare(strict_types=1);

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

Route::view('/student', 'student.home')
    ->middleware('auth')
    ->name('student.home');

Route::get('/student/ping', [PingController::class, 'student'])->name('student.ping');
