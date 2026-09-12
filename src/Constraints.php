<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use BackedEnum;

/**
 * The predicates behind the declared constraints, in one place.
 *
 * Shared so that a declared default is judged by the same rule as a value someone types: the
 * coercer asks these while reporting bad usage, the introspector while refusing a declaration.
 * Two implementations of "within min and max" would eventually disagree, and the declaration that
 * passed introspection would be the one the coercer then rejected.
 *
 * @internal Not part of the public surface; may change in any release.
 */
final class Constraints
{
    public static function atLeast(int|float $value, int|float|null $min): bool
    {
        return $min === null || $value >= $min;
    }

    public static function atMost(int|float $value, int|float|null $max): bool
    {
        return $max === null || $value <= $max;
    }

    /**
     * Does the value satisfy the pattern? A null pattern constrains nothing.
     *
     * A preg failure — the backtrack limit, or invalid UTF-8 reaching a /u pattern — is neither a
     * match nor a mismatch, and reporting it as one tells the user their value is malformed when
     * the engine is what gave up. It is ours, so it is raised as ours.
     */
    public static function matchesPattern(?string $pattern, string $value): bool
    {
        if ($pattern === null) {
            return true;
        }

        $matched = preg_match($pattern, $value);
        if ($matched === false) {
            throw new InternalError(sprintf(
                'pattern %s could not be evaluated: %s',
                $pattern,
                preg_last_error_msg(),
            ));
        }

        return $matched === 1;
    }

    /**
     * Is this value one a list of the declared element type could hold?
     *
     * A list default is written by hand, and PHP checks only the list CLASS: `new IntList(['x'])`
     * compiles, introspection saw a string element and asked it the string questions, and the
     * command received a string where its own signature says int — an uncaught TypeError as soon
     * as the list's own accessor returned it. The declared element type is the only thing that can
     * hold such a default to what a typed element will be.
     *
     * int satisfies float, as it does for a float parameter in Coercer::mismatches().
     *
     * @param 'string'|'int'|'float'|class-string<BackedEnum> $element
     */
    public static function isElement(string $element, mixed $value): bool
    {
        return match ($element) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            default => $value instanceof $element,
        };
    }
}
