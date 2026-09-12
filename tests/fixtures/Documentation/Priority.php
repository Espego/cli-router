<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests\Documentation;

/**
 * A backed enum validates itself and prints its own `Allowed:` list, so neither the body nor the
 * help ever restates which values exist.
 */
enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
