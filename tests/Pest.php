<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A real PNG of the given size, for logo checks.
 */
function logo_png(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('GD could not create an image.');
    }

    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    unset($image);

    if (! is_string($bytes) || $bytes === '') {
        throw new RuntimeException('GD could not encode a PNG.');
    }

    return $bytes;
}

/**
 * A real JPEG of the given size, for logo checks.
 */
function logo_jpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);

    if ($image === false) {
        throw new RuntimeException('GD could not create an image.');
    }

    ob_start();
    imagejpeg($image);
    $bytes = ob_get_clean();
    unset($image);

    if (! is_string($bytes) || $bytes === '') {
        throw new RuntimeException('GD could not encode a JPEG.');
    }

    return $bytes;
}
