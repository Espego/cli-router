<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

use Attribute;

/** A consumer-defined marker, to prove #[Opt(onlyWhen:)] works with an attribute the package never names. */
#[Attribute(Attribute::TARGET_METHOD)]
final class Mutates
{
}
