<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Invocation;
use Espego\CliRouter\Middleware;

/** Records that it ran, so a test can prove middleware fires for commands and not for usage errors. */
final class Trace implements Middleware
{
    /** @var list<string> */
    public static array $seen = [];

    public function handle(Invocation $invocation, callable $next): CommandResult
    {
        self::$seen[] = $invocation->name;
        $invocation->output->err("trace: {$invocation->name}\n");

        return $next();
    }
}
