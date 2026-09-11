<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/**
 * Runs after the invocation is fully validated and before the command body.
 *
 * The one imperative hook. It exists for the things that are genuinely per-invocation rather than
 * per-command — printing which environment the process is wired to, asserting the caller meant
 * this one — and which must not fire for a typo that never reached a command at all.
 */
interface Middleware
{
    /** @param callable(): CommandResult $next */
    public function handle(Invocation $invocation, callable $next): CommandResult;
}
