<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;

/**
 * Student course registration page.
 */
class CourseRegistrationController extends Controller
{
    /**
     * Show registration for the signed-in student.
     */
    public function __invoke(): View
    {
        CurrentStudent::require();

        return view('student.course-registration');
    }
}
