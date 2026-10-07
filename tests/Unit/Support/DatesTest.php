<?php

declare(strict_types=1);

use App\Support\Dates;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the application timezone on Africa/Lagos', function () {
    expect(config('app.timezone'))->toBe('Africa/Lagos')
        ->and(Dates::TIMEZONE)->toBe('Africa/Lagos');
});

it('shows an em dash for null and invalid values', function () {
    expect(Dates::date(null))->toBe('—')
        ->and(Dates::dateTime(null))->toBe('—')
        ->and(Dates::date(''))->toBe('—')
        ->and(Dates::date('not-a-date'))->toBe('—')
        ->and(Dates::dateTime('2026-13-40'))->toBe('—')
        ->and(Dates::date(false))->toBe('—');
});

it('formats midnight and a normal Lagos clock time', function () {
    $midnight = new DateTimeImmutable('2026-10-05 00:00:00', new DateTimeZone('Africa/Lagos'));
    $afternoon = new DateTimeImmutable('2026-10-05 14:30:00', new DateTimeZone('Africa/Lagos'));

    expect(Dates::date($midnight))->toBe('05 Oct 2026')
        ->and(Dates::dateTime($midnight))->toBe('05 Oct 2026, 00:00')
        ->and(Dates::date($afternoon))->toBe('05 Oct 2026')
        ->and(Dates::dateTime($afternoon))->toBe('05 Oct 2026, 14:30');
});

it('converts a utc instant to lagos without shifting a lagos clock time', function () {
    $utc = new DateTimeImmutable('2026-10-04 23:00:00', new DateTimeZone('UTC'));
    $same_instant_in_lagos = new DateTimeImmutable('2026-10-05 00:00:00', new DateTimeZone('Africa/Lagos'));

    expect(Dates::dateTime($utc))->toBe('05 Oct 2026, 00:00')
        ->and(Dates::date($utc))->toBe('05 Oct 2026')
        ->and(Dates::dateTime($same_instant_in_lagos))->toBe('05 Oct 2026, 00:00')
        ->and(Dates::dateTime('2026-10-04 23:30:00', 'UTC'))->toBe('05 Oct 2026, 00:30')
        ->and(Dates::dateTime('2026-10-05 00:30:00', 'Africa/Lagos'))->toBe('05 Oct 2026, 00:30')
        ->and(Dates::dateTime('2026-10-04T23:00:00Z'))->toBe('05 Oct 2026, 00:00');
});

it('renders the date component', function () {
    $html = Blade::render('<x-date value="2026-10-05 14:30:00" mode="datetime" source-timezone="Africa/Lagos" />');
    $missing = Blade::render('<x-date :value="null" />');

    expect($html)->toContain('05 Oct 2026, 14:30')
        ->and($missing)->toContain('—');
});
