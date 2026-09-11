<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

use Espego\CliRouter\EnumList;

/**
 * A consumer-defined enum list. The enum is named twice here — once for the runtime, once for the
 * analyser — and never again at any option that uses it.
 *
 * @extends EnumList<Colour>
 */
final class NoteTypeList extends EnumList
{
    public static function elementType(): string
    {
        return Colour::class;
    }
}
