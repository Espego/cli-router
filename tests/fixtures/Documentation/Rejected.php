<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests\Documentation;

use RuntimeException;

/** The store's considered "no" — a result, not a fault, so `#[CatchAs]` may map it. */
final class Rejected extends RuntimeException
{
}
