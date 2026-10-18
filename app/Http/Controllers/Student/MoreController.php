<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Support\CurrentStudent;
use App\Support\StudentNavigation;
use Illuminate\Contracts\View\View;

/**
 * Secondary student pages that already exist.
 */
class MoreController extends Controller
{
    /**
     * List the pages a student can open.
     */
    public function __invoke(): View
    {
        CurrentStudent::require();

        return view('student.more', [
            'pages' => StudentNavigation::pages(),
        ]);
    }
}
