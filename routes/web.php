<?php

declare(strict_types=1);

use App\Support\DesignPreviewTable;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

if (app()->environment('local')) {
    Route::get('/design-preview', function () {
        return view('design-preview', [
            'preview_table' => DesignPreviewTable::fromQuery(request()->query()),
        ]);
    })->name('design-preview');

    Route::view('/design-preview/student', 'design-preview-student')->name('design-preview.student');
}
