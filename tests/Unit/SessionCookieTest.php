<?php

declare(strict_types=1);

use App\Support\SessionCookie;

it('marks the session cookie secure outside the local environment', function () {
    expect(SessionCookie::isSecure('production', null))->toBeTrue()
        ->and(SessionCookie::isSecure('testing', null))->toBeTrue()
        ->and(SessionCookie::isSecure('local', null))->toBeFalse()
        ->and(SessionCookie::isSecure('local', 'true'))->toBeTrue()
        ->and(SessionCookie::isSecure('production', 'false'))->toBeFalse();
});
