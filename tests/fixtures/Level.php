<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

/** An INT-backed enum. tryFrom() on one of these rejects a string outright under strict_types. */
enum Level: int
{
    case Low = 1;
    case High = 2;
}
