<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application and skip the Vite manifest.
     *
     * CI has no public/build and no public/hot. Pages that call @vite,
     * and the staff panel theme, would otherwise fail while a local
     * machine with either file passes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->disable_vite();
    }

    /**
     * Replace the application and skip the Vite manifest on the new one.
     *
     * A later refreshApplication() is a new container. The skip from setUp
     * does not carry over, so the next rendered page would read the manifest.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $this->disable_vite();
    }

    /**
     * Bind an empty Vite handler so a rendered page does not read public/build.
     */
    protected function disable_vite(): void
    {
        if (isset($this->app)) {
            $this->withoutVite();
        }
    }
}
