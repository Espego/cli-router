<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use ArrayIterator;
use BackedEnum;
use Traversable;

/**
 * Base for a list of one particular backed enum: `--type=Billing,Contact`.
 *
 * A subclass names its enum twice — once for the runtime in `elementType()`, once for the analyser
 * in `@extends` — and that is the last time anyone writes it. Every option of that type is then
 * just `EventTypeList $events`, and `->all()` resolves to `list<MsaEventType>`:
 *
 *     /** @extends EnumList<MsaEventType> *\/
 *     final class EventTypeList extends EnumList
 *     {
 *         public static function elementType(): string { return MsaEventType::class; }
 *     }
 *
 * @template T of BackedEnum
 */
abstract class EnumList implements ValueList
{
	/** @var list<T> */
	private readonly array $values;

	/** @param list<T> $values */
	final public function __construct(array $values = [])
	{
		$this->values = $values;
	}

	/** @param list<mixed> $values */
	public static function of(array $values): static
	{
		/** @var list<T> $values */
		return new static($values);
	}

	/** @return list<T> */
	public function all(): array
	{
		return $this->values;
	}

	/** @return T|null */
	public function first(): ?BackedEnum
	{
		return $this->values[0] ?? null;
	}

	/** @param T $value */
	public function contains(BackedEnum $value): bool
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

	/** @return Traversable<int, T> */
	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->values);
	}
}
