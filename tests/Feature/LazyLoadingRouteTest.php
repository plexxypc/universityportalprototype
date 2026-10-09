<?php

declare(strict_types=1);

use App\Support\DesignPreviewTable;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Route;

it('serves the staff login page without a lazy-loading violation', function () {
    $this->withoutVite();

    $response = $this->get('/staff/login');

    expect($response->exception)->not->toBeInstanceOf(LazyLoadingViolationException::class);

    $response->assertSuccessful();
});

it('serves the design preview pages without a lazy-loading violation', function () {
    Route::middleware('web')->group(function (): void {
        Route::get('/design-preview-lazy-check', function () {
            return view('design-preview', [
                'preview_table' => DesignPreviewTable::fromQuery(request()->query()),
            ]);
        });

        Route::view('/design-preview-student-lazy-check', 'design-preview-student');
    });

    $this->withoutVite();

    $this->get('/design-preview')->assertNotFound();
    $this->get('/design-preview/student')->assertNotFound();

    $preview = $this->get('/design-preview-lazy-check');
    $student = $this->get('/design-preview-student-lazy-check');

    expect($preview->exception)->not->toBeInstanceOf(LazyLoadingViolationException::class)
        ->and($student->exception)->not->toBeInstanceOf(LazyLoadingViolationException::class);

    $preview->assertOk();
    $student->assertOk();
});
