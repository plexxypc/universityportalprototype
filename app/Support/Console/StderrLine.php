<?php

declare(strict_types=1);

namespace App\Support\Console;

/**
 * Writes one line to stderr.
 *
 * The host log collects the scheduler's stderr. Callers pass counts only.
 */
final class StderrLine
{
    /**
     * @var resource
     */
    private $stream;

    public function __construct()
    {
        $this->stream = STDERR;
    }

    /**
     * Point later lines at a stream. Tests use a memory stream.
     *
     * @param  resource  $stream
     */
    public function useStream($stream): void
    {
        $this->stream = $stream;
    }

    /**
     * Write one line and a newline.
     */
    public function write(string $line): void
    {
        fwrite($this->stream, $line.PHP_EOL);
    }
}
