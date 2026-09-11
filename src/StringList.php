<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use ArrayIterator;
use Traversable;

/**
 * `--msa=IC01,IC02,IC03`.
 *
 * Declare the parameter as `StringList $msa` and the element type is stated once, in the signature.
 * An empty list is a real answer — "given, and empty" is not the same as "not given", which is
 * `?StringList $msa = null`.
 */
final class StringList implements ValueList
{
	/** @var list<string> */
	private readonly array $values;

	/** @param list<string> $values */
	public function __construct(array $values = [])
	{
		$this->values = $values;
	}

	public static function elementType(): string
	{
		return 'string';
	}

	/** @param list<mixed> $values */
	public static function of(array $values): static
	{
		/** @var list<string> $values */
		return new self($values);
	}

	/** @return list<string> */
	public function all(): array
	{
		return $this->values;
	}

	public function first(): ?string
	{
		return $this->values[0] ?? null;
	}

	public function contains(string $value): bool
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

	/** @return Traversable<int, string> */
	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->values);
	}
}
