<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use ReflectionMethod;

/** @internal Not part of the public surface; may change in any release. */
final class CommandInfo
{
    /** @param list<ValueSpec> $params In declaration order — which is command-line order. */
    public function __construct(
        public readonly string $name,
        public readonly ReflectionMethod $method,
        public readonly Command $meta,
        public readonly array $params,
    ) {
    }

    /** @return list<ValueSpec> */
    public function options(): array
    {
        return array_values(array_filter($this->params, static fn (ValueSpec $p): bool => ! $p->positional));
    }

    /** @return list<ValueSpec> */
    public function positionals(): array
    {
        return array_values(array_filter($this->params, static fn (ValueSpec $p): bool => $p->positional));
    }

    public function variadic(): ?ValueSpec
    {
        foreach ($this->params as $p) {
            if ($p->variadic) {
                return $p;
            }
        }

        return null;
    }

    /** @param class-string $attribute */
    public function marked(string $attribute): bool
    {
        return $this->method->getAttributes($attribute) !== [];
    }
}
