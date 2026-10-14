<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Blade is compiled once per process, outside the application view cache.
     */
    private static bool $views_ready = false;

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
        $this->prepare_compiled_views();
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

    /**
     * Compile Blade once for this process.
     *
     * The compiled path is set before the application boots. The first staff
     * page otherwise compiles hundreds of templates on that request.
     */
    protected function prepare_compiled_views(): void
    {
        if (self::$views_ready) {
            return;
        }

        $this->artisan('view:cache')->assertSuccessful();
        self::$views_ready = true;
    }
}
