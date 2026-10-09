<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('gives every application table a model and a factory', function () {
    $framework_tables = [
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    $tables = collect(Schema::getTables())
        ->map(function (array $table): string {
            $name = $table['name'];

            return str_contains($name, '.') ? substr($name, strrpos($name, '.') + 1) : $name;
        })
        ->reject(fn (string $name): bool => in_array($name, $framework_tables, true))
        ->sort()
        ->values();

    $models = collect(File::files(app_path('Models')))
        ->map(fn (SplFileInfo $file): string => 'App\\Models\\'.$file->getBasename('.php'))
        ->filter(fn (string $class): bool => is_subclass_of($class, Model::class));

    $model_tables = $models
        ->map(fn (string $class): string => (new $class)->getTable())
        ->sort()
        ->values();

    expect($tables->diff($model_tables)->all())->toBe([])
        ->and($model_tables->diff($tables)->all())->toBe([]);

    foreach ($models as $class) {
        expect(in_array(HasFactory::class, class_uses_recursive($class), true))->toBeTrue()
            ->and($class::factory())->toBeInstanceOf(Factory::class);
    }
});
