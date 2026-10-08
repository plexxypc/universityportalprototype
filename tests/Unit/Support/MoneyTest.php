<?php

declare(strict_types=1);

use App\Support\Money;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

it('formats zero kobo as naira', function () {
    expect(Money::format(0))->toBe('₦0.00');
});

it('formats a whole naira amount with separators', function () {
    expect(Money::format(12_500_000))->toBe('₦125,000.00')
        ->and(Money::format(1))->toBe('₦0.01')
        ->and(Money::format(100))->toBe('₦1.00');
});

it('formats an amount above 2^53 kobo without rounding', function () {
    $kobo = Money::parseNaira('90071992547409.93');

    expect($kobo)->toBe(9_007_199_254_740_993)
        ->and(is_int($kobo))->toBeTrue()
        ->and(Money::format($kobo))->toBe('₦90,071,992,547,409.93');
});

it('parses zero and a typed naira string', function () {
    expect(Money::parseNaira('0'))->toBe(0)
        ->and(Money::parseNaira('0.00'))->toBe(0)
        ->and(Money::parseNaira('0.5'))->toBe(50)
        ->and(Money::parseNaira('₦125,000.00'))->toBe(12_500_000)
        ->and(Money::parseNaira(' 10.50 '))->toBe(1_050);
});

it('rejects more than two decimal places instead of rounding', function () {
    expect(fn () => Money::parseNaira('1.005'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parseNaira('1.000'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parseNaira('10.999'))->toThrow(InvalidArgumentException::class);
});

it('rejects blank and non-numeric amounts', function () {
    expect(fn () => Money::parseNaira(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parseNaira('abc'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::parseNaira('1.2.3'))->toThrow(InvalidArgumentException::class);
});

it('keeps a negative parse and rejects it as a payment amount', function () {
    expect(Money::parseNaira('-1.50'))->toBe(-150)
        ->and(Money::format(-150))->toBe('-₦1.50')
        ->and(fn () => Money::paymentAmount(-150))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Money::paymentAmount(0))->toThrow(InvalidArgumentException::class)
        ->and(Money::paymentAmount(150))->toBe(150)
        ->and(Money::paymentAmount(Money::parseNaira('0.01')))->toBe(1);
});

it('renders the money component', function () {
    $html = Blade::render('<x-money :kobo="12500000" />');

    expect($html)->toContain('₦125,000.00')
        ->and($html)->toContain('tabular-nums');
});
