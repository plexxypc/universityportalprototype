<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application and skip the Vite manifest.
     *
     * CI has no public/build and no public/hot. Pages that call @vite
     * would otherwise fail while a local machine with either file passes.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (isset($this->app)) {
            $this->withoutVite();
        }
    }
}
