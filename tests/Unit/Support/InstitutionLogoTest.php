<?php

declare(strict_types=1);

use App\Support\InstitutionLogo;

it('accepts a small png', function () {
    $logos = new InstitutionLogo;
    $png = $logos->inspect(logo_png(8, 8), 1024 * 1024);

    expect($png)->toBe([
        'extension' => 'png',
        'mime' => 'image/png',
    ]);
});

it('refuses an svg logo', function () {
    $logos = new InstitutionLogo;
    $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>';

    expect(fn () => $logos->inspect($svg, 1024 * 1024))
        ->toThrow(InvalidArgumentException::class, InstitutionLogo::MESSAGE_TYPE);
});

it('refuses a corrupt logo', function () {
    $logos = new InstitutionLogo;

    expect(fn () => $logos->inspect(substr(logo_png(8, 8), 0, 24), 1024 * 1024))
        ->toThrow(InvalidArgumentException::class, InstitutionLogo::MESSAGE_READ);
});

it('refuses an oversize logo', function () {
    $logos = new InstitutionLogo;

    expect(fn () => $logos->inspect(str_repeat('A', 32), 16))
        ->toThrow(InvalidArgumentException::class, InstitutionLogo::MESSAGE_SIZE);
});

it('refuses a logo larger than 2000 by 2000 pixels', function () {
    $logos = new InstitutionLogo;

    expect(fn () => $logos->inspect(logo_png(2001, 1), 1024 * 1024))
        ->toThrow(InvalidArgumentException::class, InstitutionLogo::MESSAGE_DIMENSIONS);
});

it('refuses a logo whose declared content type does not match', function () {
    $logos = new InstitutionLogo;

    expect(fn () => $logos->inspect(logo_jpeg(8, 8), 1024 * 1024, 'image/png'))
        ->toThrow(InvalidArgumentException::class, InstitutionLogo::MESSAGE_MISMATCH);
});
