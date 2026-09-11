<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

use RuntimeException;

/** Stands in for an upstream system's considered "no". */
final class Refused extends RuntimeException
{
}
