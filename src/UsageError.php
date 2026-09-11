<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use RuntimeException;

/**
 * The invocation was wrong: a missing option, a bad value, an unknown command.
 *
 * Thrown rather than exited so a helper deep in a call stack can still report bad usage, and so a
 * test can assert on it. The runner turns it into one `error: …` line on stderr and the exit code.
 */
class UsageError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $exitCode = 1
    ) {
        parent::__construct($message);
    }
}
