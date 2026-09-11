<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use RuntimeException;

/**
 * A non-error early exit from deep inside a helper, carrying the result to emit.
 *
 * Rare on purpose — a command that can return its result should return it. This exists for the
 * case where the decision is made several frames down and threading a return value back would
 * obscure it.
 */
final class Stop extends RuntimeException
{
    public function __construct(
        public readonly CommandResult $result
    ) {
        parent::__construct('command stopped');
    }
}
