<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Session cookie flags for the portal.
 */
final class SessionCookie
{
    /**
     * Secure is on outside local unless an explicit boolean override is set.
     *
     * @param  mixed  $configured  Value of SESSION_SECURE_COOKIE, or null when unset.
     */
    public static function isSecure(string $environment, mixed $configured): bool
    {
        if (is_bool($configured)) {
            return $configured;
        }

        if (is_string($configured) && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        return $environment !== 'local';
    }
}
