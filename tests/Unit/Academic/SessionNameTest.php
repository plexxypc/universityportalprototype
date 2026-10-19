<?php

declare(strict_types=1);

use App\Services\AcademicService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Run the session-name rule without a database.
 */
function session_name_check(string $name): string
{
    $service = app(AcademicService::class);
    $method = new ReflectionMethod(AcademicService::class, 'sessionName');
    $checked = $method->invoke($service, $name);

    if (! is_string($checked)) {
        throw new RuntimeException('The session name check did not return a string.');
    }

    return $checked;
}

it('accepts only a session name whose second year is the next year', function () {
    expect(session_name_check('2026/2027'))->toBe('2026/2027');

    expect(fn () => session_name_check('2026/2028'))
        ->toThrow(ValidationException::class, AcademicService::MESSAGE_NAME);

    expect(fn () => session_name_check('2026-2027'))
        ->toThrow(ValidationException::class, AcademicService::MESSAGE_NAME);
});
