<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use RuntimeException;

final class StreamOutput implements Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? \STDOUT;
        $this->stderr = $stderr ?? \STDERR;
    }

    public function out(string $bytes): void
    {
        $this->writeAll($this->stdout, $bytes, 'stdout');
    }

    public function err(string $bytes): void
    {
        $this->writeAll($this->stderr, $bytes, 'stderr');
    }

    /**
     * fwrite() may write fewer bytes than it was given, and returns false on failure. Ignoring
     * either means truncated JSON that still exits 0 — silently wrong output being worse than no
     * output, this throws instead.
     *
     * @param resource $stream
     */
    private function writeAll($stream, string $bytes, string $name): void
    {
        for ($written = 0; $written < strlen($bytes);) {
            $chunk = fwrite($stream, substr($bytes, $written));
            if ($chunk === false || $chunk === 0) {
                throw new RuntimeException("could not write to {$name}");
            }
            $written += $chunk;
        }
    }
}
