<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use ReflectionMethod;

/** The command that is running, handed to middleware and readable from a command body. */
final class Invocation
{
	/** @param list<mixed> $arguments Positional, in declaration order. */
	public function __construct(
		public readonly string $name,
		public readonly ReflectionMethod $method,
		public readonly array $arguments,
		public readonly Output $output,
	) {
	}

	/**
	 * Does the command carry this marker attribute?
	 *
	 * How a set asks "is this one of my write commands" without the answer being a list of command
	 * names kept somewhere else.
	 *
	 * @param class-string $attribute
	 */
	public function marked(string $attribute): bool
	{
		return $this->method->getAttributes($attribute) !== [];
	}
}
