<?php

declare(strict_types=1);

namespace Espego\CliRouter;

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
        fwrite($this->stdout, $bytes);
    }

    public function err(string $bytes): void
    {
        fwrite($this->stderr, $bytes);
    }
}
