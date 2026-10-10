<?php

declare(strict_types=1);

namespace App\Support\Mail;

/**
 * HTML and plain-text parts held in memory for one send.
 */
final class RenderedMail
{
    public function __construct(
        public string $html,
        public string $text,
    ) {}
}
