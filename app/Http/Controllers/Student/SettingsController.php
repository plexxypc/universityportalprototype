<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;

/**
 * Student account settings.
 *
 * Name, matric number, and email are read from the signed-in student.
 * The password form posts to the existing change-password action.
 */
class SettingsController extends Controller
{
    /**
     * Show the signed-in student's account settings.
     */
    public function __invoke(): View
    {
        $student = CurrentStudent::require();
        $student->loadMissing('user');

        return view('student.settings', [
            'student' => $student,
            'full_name' => CurrentStudent::fullName($student),
        ]);
    }
}
