<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use Attribute;

/**
 * A positional argument. Declaration order is command-line order; a variadic takes the rest.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Arg extends Param
{
    /** @param bool $required Variadic only: at least one value must be given. */
    public function __construct(
        string $description = '',
        ?string $placeholder = null,
        /** @var non-empty-string */
        string $separator = ',',
        ?string $pattern = null,
        ?string $hint = null,
        int|float|null $min = null,
        int|float|null $max = null,
        ?int $minCount = null,
        ?int $maxCount = null,
        bool $allowEmpty = false,
        bool $trim = true,
        public bool $required = false,
    ) {
        parent::__construct($description, $placeholder, $separator, $pattern, $hint, $min, $max, $minCount, $maxCount, $allowEmpty, $trim);
    }
}
