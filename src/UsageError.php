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
        // Zero is the one that matters: fail('bad', 0) printed a diagnostic and reported success,
        // so a wrapper script saw a clean run. Above 255 the shell reads one byte and invents a
        // code nobody declared.
        if ($exitCode < 1 || $exitCode > 255) {
            throw new DeclarationError("a UsageError exit code is 1-255, not {$exitCode}.");
        }

        parent::__construct($message);
    }
}
