<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Livewire\Component;

/**
 * Student-area probe used only by the access middleware tests.
 *
 * This class is not a portal screen. The route that mounts it exists only
 * while the application is running tests.
 */
class StudentAreaProbe extends Component
{
    /**
     * Render a marker the access tests can find in the first response.
     */
    public function render(): string
    {
        return '<div id="student-area-probe">Student area probe</div>';
    }
}
