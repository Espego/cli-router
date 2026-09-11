<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use ArrayIterator;
use Traversable;

/**
 * `--instance=1,2,3`.
 *
 * Each element goes through the same validation a plain `int` option does, so `--instance=1,x,3`
 * is refused with the element that failed rather than silently becoming `0`.
 */
final class IntList implements ValueList
{
    /** @var list<int> */
    private readonly array $values;

    /** @param list<int> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public static function elementType(): string
    {
        return 'int';
    }

    /** @param list<mixed> $values */
    public static function of(array $values): static
    {
        /** @var list<int> $values */
        return new self($values);
    }

    /** @return list<int> */
    public function all(): array
    {
        return $this->values;
    }

    public function first(): ?int
    {
        return $this->values[0] ?? null;
    }

    public function contains(int $value): bool
    {
        return in_array($value, $this->values, true);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function count(): int
    {
        return count($this->values);
    }

    /** @return Traversable<int, int> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }
}
