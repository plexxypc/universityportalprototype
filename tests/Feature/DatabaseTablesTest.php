<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('migrates session cache queue and password reset tables', function () {
    expect(Schema::getConnection()->getDriverName())->toBe('mysql')
        ->and(Schema::getConnection()->getDatabaseName())->toBe('university_portal_testing')
        ->and(Schema::hasTable('sessions'))->toBeTrue()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeTrue()
        ->and(Schema::hasTable('cache'))->toBeTrue()
        ->and(Schema::hasTable('cache_locks'))->toBeTrue()
        ->and(Schema::hasTable('jobs'))->toBeTrue()
        ->and(Schema::hasTable('job_batches'))->toBeTrue()
        ->and(Schema::hasTable('failed_jobs'))->toBeTrue();
});
