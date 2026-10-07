<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/student/ping', static function (): string {
    return 'student routes ok';
})->name('student.ping');
