<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests\Documentation;

use Attribute;

/**
 * Marks a command as one that changes something.
 *
 * The package never names this attribute; `#[Opt(onlyWhen: Writes::class)]` on the set's
 * `--confirm` global is what connects the two. That is what makes the write gate a declaration
 * rather than a convention every new command has to remember.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Writes
{
}
