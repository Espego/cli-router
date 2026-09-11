<?php

declare(strict_types=1);

namespace Espego\CliRouter;

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
}
