<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

use Espego\CliRouter\EnumList;

/** @extends EnumList<Level> */
final class LevelList extends EnumList
{
    public static function elementType(): string
    {
        return Level::class;
    }
}
