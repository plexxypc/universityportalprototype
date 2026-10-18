<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Support\CurrentStudent;
use Illuminate\Contracts\View\View;

/**
 * Student documents page.
 */
class DocumentController extends Controller
{
    /**
     * Show the signed-in student's documents.
     */
    public function __invoke(): View
    {
        CurrentStudent::require();

        return view('student.documents');
    }
}
