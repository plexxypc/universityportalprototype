<?php

declare(strict_types=1);

use App\Support\Dates;

it('parses deadlines as Africa Lagos wall time', function () {
    $naive = Dates::storageDateTime('2026-10-12 14:30:00');
    $utc = Dates::storageDateTime(new DateTimeImmutable('2026-10-12 13:30:00', new DateTimeZone('UTC')));

    expect($naive)->toBe('2026-10-12 14:30:00')
        ->and($utc)->toBe('2026-10-12 14:30:00');
});
