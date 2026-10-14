<?php

declare(strict_types=1);

use App\Support\TemporaryPassword;

it('builds a 12 character temporary password from the approved alphabet', function () {
    $first = TemporaryPassword::generate();
    $second = TemporaryPassword::generate();
    $pattern = '/^['.preg_quote(TemporaryPassword::ALPHABET, '/').']+$/';
    $first_ok = strlen($first) === TemporaryPassword::LENGTH
        && preg_match($pattern, $first) === 1
        && preg_match('/\p{L}/u', $first) === 1
        && preg_match('/\d/', $first) === 1;
    $second_ok = strlen($second) === TemporaryPassword::LENGTH
        && preg_match($pattern, $second) === 1
        && preg_match('/\p{L}/u', $second) === 1
        && preg_match('/\d/', $second) === 1;
    $differ = $first !== $second;

    expect($first_ok && $second_ok && $differ)->toBeTrue();
});
