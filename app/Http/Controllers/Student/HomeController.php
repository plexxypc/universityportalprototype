<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;

/**
 * Student home. The cards load in a lazy component that checks the student again.
 */
class HomeController extends Controller
{
    /**
     * Show the dashboard shell for the signed-in student.
     */
    public function __invoke(): View
    {
        CurrentStudent::require();

        return view('student.home');
    }
}
