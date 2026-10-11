<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;

/**
 * Load the environment file only when it exists.
 *
 * The framework loader always asks phpdotenv to read `.env`. That read uses
 * file_get_contents, and a missing file is then ignored. PHPUnit still
 * reports the warning, because phpdotenv is not on its exclude list. CI
 * checks out the repository without a `.env` file. phpunit.xml supplies
 * the test values, so the missing file must not be opened.
 */
final class EnvironmentFileLoader extends LoadEnvironmentVariables
{
    /**
     * Skip the read when neither `.env` nor `.env.{APP_ENV}` is present.
     */
    public function bootstrap(Application $app): void
    {
        if ($app->configurationIsCached()) {
            return;
        }

        $this->checkForSpecificEnvironmentFile($app);

        if (! is_file($app->environmentFilePath())) {
            return;
        }

        parent::bootstrap($app);
    }
}
