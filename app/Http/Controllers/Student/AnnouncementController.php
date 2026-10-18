<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;

/**
 * Student announcements page.
 *
 * Audience matching is not built. This page does not read announcements.
 */
class AnnouncementController extends Controller
{
    /**
     * Show the empty announcements list for the signed-in student.
     */
    public function __invoke(): View
    {
        CurrentStudent::require();

        return view('student.announcements');
    }
}
