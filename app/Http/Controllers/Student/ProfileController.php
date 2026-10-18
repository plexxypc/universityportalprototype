<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;

/**
 * Student profile page.
 */
class ProfileController extends Controller
{
    /**
     * Show the signed-in student's profile.
     */
    public function __invoke(): View
    {
        CurrentStudent::require();

        return view('student.profile');
    }
}
