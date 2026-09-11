<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use Attribute;

/**
 * A `--flag` or `--key=value`.
 *
 * On a method parameter it is that command's option. On a public property of the command set it is
 * a global, offered to every command — or, with `onlyWhen`, to every command carrying a given
 * marker attribute.
 *
 * The flag name IS the parameter name, kebab-cased: `$intendedEnv` is `--intended-env`. Renaming
 * the parameter renames the flag, which is the price of having one source of truth; a help
 * snapshot test turns that into a visible diff rather than a surprise.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final class Opt extends Param
{
    /**
     * @param class-string|null $onlyWhen Globals only: a method attribute a command must carry for
     *     this option to apply to it. Lets a set say "only write commands take --confirm" once,
     *     instead of repeating the option on every such command.
     */
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
        public ?string $onlyWhen = null,
    ) {
        parent::__construct($description, $placeholder, $separator, $pattern, $hint, $min, $max, $minCount, $maxCount, $allowEmpty, $trim);
    }
}
