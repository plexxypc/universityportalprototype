<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DesignPreviewTest extends TestCase
{
    /**
     * Confirm the design preview is not registered outside the local environment.
     */
    public function test_design_preview_is_not_available_outside_local(): void
    {
        $this->get('/design-preview')->assertNotFound();
    }
}
