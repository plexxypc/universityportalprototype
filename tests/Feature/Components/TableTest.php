<?php

declare(strict_types=1);

use App\Support\DesignPreviewTable;
use Illuminate\Support\Facades\Blade;

it('renders sort state, pagination and the mobile card', function () {
    $table = DesignPreviewTable::fromQuery([]);
    $html = Blade::render(<<<'BLADE'
        <x-table
            :columns="$columns"
            :rows="$rows"
            :paginator="$paginator"
            :sort="$sort"
            :direction="$direction"
            caption="Sample students"
        >
            <x-slot:toolbar>
                <p>Search</p>
            </x-slot:toolbar>
            <x-slot:empty>
                <p>No students match this search.</p>
            </x-slot:empty>
        </x-table>
    BLADE, [
        'columns' => $table['columns'],
        'rows' => $table['rows'],
        'paginator' => $table['paginator'],
        'sort' => $table['sort'],
        'direction' => $table['direction'],
    ]);

    expect($html)->toContain('aria-sort="ascending"')
        ->and($html)->toContain('aria-sort="none"')
        ->and($html)->toContain('sticky top-0')
        ->and($html)->toContain('md:hidden')
        ->and($html)->toContain('>View</a>')
        ->and($html)->toContain('Ada Okonkwo')
        ->and($html)->toContain('Matric number')
        ->and($html)->toContain('Page 1 of 2')
        ->and($html)->toContain('Next')
        ->and($html)->toContain('Search');
});

it('renders the empty state when the table has no rows', function () {
    $table = DesignPreviewTable::fromQuery(['q' => 'zzzz-not-a-student']);
    $html = Blade::render(<<<'BLADE'
        <x-table :columns="$columns" :rows="$rows" caption="Matching students">
            <x-slot:empty>
                <p>No students match this search.</p>
            </x-slot:empty>
        </x-table>
    BLADE, [
        'columns' => $table['columns'],
        'rows' => $table['rows'],
    ]);

    expect($html)->toContain('No students match this search.')
        ->and($html)->not->toContain('<table');
});
