<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use BackedEnum;
use Countable;
use IteratorAggregate;

/**
 * A multi-value option, declared as a type rather than as `array` plus a docblock.
 *
 * `array $msa` says nothing about its elements, so PHPStan needs `@param list<string> $msa` and the
 * attribute needs to restate the same fact — two more places for one truth to drift. A list TYPE
 * carries it in the signature, where the analyser already looks and where nothing can disagree
 * with it.
 *
 * Implementations declare their element type and build themselves; the parsing, the range checks
 * and the error messages stay in the coercer, so `--ids=1,x,3` reports the same way a plain `int`
 * option would.
 *
 * A required parameter must declare `minCount: 0` when an explicit empty list has meaning, or
 * `minCount: 1` (or more) when it must be refused. Introspection rejects an omitted policy.
 *
 * @extends IteratorAggregate<int, mixed>
 */
interface ValueList extends IteratorAggregate, Countable
{
    /**
     * What each element must become: `'string'`, `'int'`, `'float'`, or a backed enum class.
     *
     * @return 'string'|'int'|'float'|class-string<BackedEnum>
     */
    public static function elementType(): string;

    /**
     * Build from elements the coercer has already validated and converted.
     *
     * @param list<mixed> $values
     */
    public static function of(array $values): static;
}
