<?php

declare(strict_types=1);

use App\Support\DesignPreviewTable;
use Tests\TestCase;

uses(TestCase::class);

it('pages demo rows at 25 and sorts without a database', function () {
    $first = DesignPreviewTable::fromQuery([]);
    $second = DesignPreviewTable::fromQuery(['page' => 2]);
    $descending = DesignPreviewTable::fromQuery(['sort' => 'name', 'direction' => 'desc']);
    $empty = DesignPreviewTable::fromQuery(['q' => 'zzzz-not-a-student']);

    expect($first['paginator']->perPage())->toBe(25)
        ->and($first['paginator']->total())->toBe(30)
        ->and($first['rows'])->toHaveCount(25)
        ->and($first['rows'][0]['name'])->toBe('Ada Okonkwo')
        ->and($second['rows'])->toHaveCount(5)
        ->and($descending['rows'][0]['name'])->toBe('Zainab Lawal')
        ->and($empty['rows'])->toBe([])
        ->and($first['columns'][0]['href'])->toContain('direction=desc');
});
