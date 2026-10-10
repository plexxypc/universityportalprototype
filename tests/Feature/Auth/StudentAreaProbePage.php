<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\View as ViewFactory;
use Livewire\Livewire;

/**
 * Page that mounts the test-only student probe inside the student routes.
 */
class StudentAreaProbePage
{
    /**
     * Render the probe. The view namespace lives under the tests directory.
     */
    public function __invoke(): View
    {
        ViewFactory::addNamespace('auth-probe', __DIR__.'/views');
        Livewire::component('student-area-probe', StudentAreaProbe::class);

        return view('auth-probe::probe');
    }
}
