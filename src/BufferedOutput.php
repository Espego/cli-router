<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/** Collects both streams in memory so a test can assert on them separately. */
final class BufferedOutput implements Output
{
    public string $out = '';

    public string $err = '';

    public function out(string $bytes): void
    {
        $this->out .= $bytes;
    }

    public function err(string $bytes): void
    {
        $this->err .= $bytes;
    }
}
