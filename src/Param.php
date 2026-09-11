<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/**
 * Common arguments of the two parameter declarations.
 *
 * Never applied directly, so it carries no #[Attribute] marker — an attribute subclass needs its
 * own, and an unmarked base cannot be used by mistake.
 *
 * Everything the type system can state is read from the native signature instead of declared here:
 * the name, the type, required-vs-optional, the default, and an enum's cases. What is left is what
 * a type cannot say.
 */
abstract class Param
{
	/**
	 * @param string $description One logical line; the help renderer wraps it.
	 * @param string|null $placeholder What follows `=` in the help. Null derives it from the type.
	 * @param non-empty-string $separator What a list-typed parameter splits its raw value on.
	 * @param non-empty-string|null $pattern preg the raw value must match.
	 * @param string|null $hint Sentence appended to a constraint failure.
	 * @param int|null $min Integers: minimum value. Variadic arguments: minimum count.
	 * @param int|null $max Integers: maximum value. Variadic arguments: maximum count.
	 * @param bool $allowEmpty Accept `--x=` as the empty string rather than refusing it.
	 * @param bool $trim Trim the raw value before coercing it.
	 */
	public function __construct(
		public string $description = '',
		public ?string $placeholder = null,
		/** @var non-empty-string */
		public string $separator = ',',
		public ?string $pattern = null,
		public ?string $hint = null,
		public ?int $min = null,
		public ?int $max = null,
		public bool $allowEmpty = false,
		public bool $trim = true,
	) {
	}
}
