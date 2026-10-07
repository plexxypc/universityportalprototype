<?php

declare(strict_types=1);

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

Route::get('/student/ping', [PingController::class, 'student'])->name('student.ping');
