<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Applicant;
use App\Models\Student;
use App\Support\DatabaseTls;
use App\Support\EnvironmentGuard;
use App\Support\Rbac\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(DatabaseTls::class)->apply();
        $this->app->make(EnvironmentGuard::class)->enforce();

        Relation::enforceMorphMap([
            'Applicant' => Applicant::class,
            'Student' => Student::class,
        ]);

        Model::preventLazyLoading(! $this->app->isProduction());

        Permissions::registerGates();
    }
}
