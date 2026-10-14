<?php

declare(strict_types=1);

use App\Support\Phone;

it('normalises Nigerian numbers to +234', function () {
    expect(Phone::normalise('08012345678'))->toBe('+2348012345678')
        ->and(Phone::normalise('2348012345678'))->toBe('+2348012345678')
        ->and(Phone::normalise('+234 801 234 5678'))->toBe('+2348012345678')
        ->and(Phone::normalise('8012345678'))->toBe('+2348012345678')
        ->and(Phone::normalise('  '))->toBeNull()
        ->and(Phone::normalise(null))->toBeNull();
});

it('rejects a number that is not a Nigerian phone', function () {
    expect(fn () => Phone::normalise('12345'))
        ->toThrow(InvalidArgumentException::class, Phone::MESSAGE);
});
