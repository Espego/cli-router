<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Turns raw argv strings into the values a command's signature demands.
 *
 * This class is load-bearing, not a convenience. Reflection invocation reports a missing argument
 * as `ArgumentCountError: Too few arguments…` and a bad type as `TypeError: Argument #1 ($n) must
 * be of type int, string given` — neither is acceptable output for someone who mistyped a flag.
 * Every such failure is caught here and reported in the package's own words, before the command is
 * called at all.
 */
final class Coercer
{
	/**
	 * Values for the set's public global properties.
	 *
	 * @param array<string, string|true> $opts
	 * @return array<string, mixed> keyed by PHP property name
	 */
	public function globals(SetInfo $set, CommandInfo $command, array $opts): array
	{
		$values = [];

		foreach ($set->globalsFor($command) as $global) {
			$values[$global->phpName] = array_key_exists($global->cliName, $opts)
				? $this->value($global, $opts[$global->cliName])
				: $global->default;
		}

		return $values;
	}

	/**
	 * The full positional argument list for the call, every parameter resolved in declaration order.
	 *
	 * Always positional, never named. Named-argument dispatch works for ordinary parameters but
	 * silently breaks on a variadic — `['files' => ['a', 'b']]` passes the array as one argument —
	 * and two dispatch paths would leave the variadic one exercised by whichever single command
	 * happens to use it.
	 *
	 * @param array<string, string|true> $opts
	 * @param list<string> $args
	 * @return list<mixed>
	 */
	public function arguments(CommandInfo $command, array $opts, array $args): array
	{
		$call = [];
		$position = 0;

		foreach ($command->params as $spec) {
			if ($spec->variadic) {
				$rest = array_slice($args, $position);
				$position = count($args);

				$this->assertCount($spec, count($rest));
				foreach ($rest as $raw) {
					$call[] = $this->value($spec, $raw);
				}
				continue;
			}

			if ($spec->positional) {
				if ($position < count($args)) {
					$call[] = $this->value($spec, $args[$position++]);
					continue;
				}
				if ($spec->isRequired()) {
					throw new UsageError("missing required argument <{$spec->placeholder()}>");
				}
				$call[] = $spec->default;
				continue;
			}

			if (array_key_exists($spec->cliName, $opts)) {
				$call[] = $this->value($spec, $opts[$spec->cliName]);
				continue;
			}
			if ($spec->isRequired()) {
				throw new UsageError("--{$spec->cliName} is required and must have a value");
			}
			$call[] = $spec->default;
		}

		if ($position < count($args)) {
			$extra = array_slice($args, $position);
			throw new UsageError(sprintf(
				'unexpected argument%s: %s',
				count($extra) === 1 ? '' : 's',
				implode(', ', array_map(static fn(string $a): string => "'{$a}'", $extra)),
			));
		}

		return $call;
	}

	/**
	 * Prove the built list satisfies the signature before invoking it.
	 *
	 * With this in place a TypeError escaping the call can only have come from inside the command
	 * body, where it is a real fault — so the runner never needs a blanket catch that would swallow
	 * genuine bugs and report them as bad usage.
	 *
	 * @param list<mixed> $arguments
	 */
	public function assertCallable(ReflectionMethod $method, array $arguments): void
	{
		$parameters = $method->getParameters();
		$required = $method->getNumberOfRequiredParameters();

		if (count($arguments) < $required) {
			throw new InternalError(sprintf(
				'%s::%s() needs %d arguments, the router built %d.',
				$method->getDeclaringClass()->getName(),
				$method->getName(),
				$required,
				count($arguments),
			));
		}

		foreach ($arguments as $i => $value) {
			$parameter = $parameters[$i] ?? ($parameters[count($parameters) - 1] ?? null);
			if ($parameter === null) {
				throw new InternalError($method->getName() . '() received more arguments than it declares.');
			}

			$type = $parameter->getType();
			if (!$type instanceof ReflectionNamedType || !$this->mismatches($type, $value)) {
				continue;
			}

			throw new InternalError(sprintf(
				'%s::%s() parameter $%s expects %s, the router built %s.',
				$method->getDeclaringClass()->getName(),
				$method->getName(),
				$parameter->getName(),
				$type->getName(),
				get_debug_type($value),
			));
		}
	}

	private function mismatches(ReflectionNamedType $type, mixed $value): bool
	{
		if ($value === null) {
			return !$type->allowsNull();
		}

		return match ($type->getName()) {
			'mixed', 'iterable' => false,
			'string' => !is_string($value),
			'int' => !is_int($value),
			'float' => !is_float($value) && !is_int($value),
			'bool' => !is_bool($value),
			'array' => !is_array($value),
			default => !is_object($value) || !is_a($value, $type->getName()),
		};
	}

	/** @param string|true $raw `true` means the flag was given bare, with no `=value`. */
	private function value(ValueSpec $spec, string|bool $raw): mixed
	{
		if ($spec->typeName === 'bool') {
			if ($raw !== true) {
				throw new UsageError("--{$spec->cliName} is a flag and takes no value");
			}

			return true;
		}

		if ($raw === true) {
			throw new UsageError($spec->positional
				? "<{$spec->placeholder()}> needs a value"
				: "--{$spec->cliName} is required and must have a value");
		}

		$value = $spec->meta->trim ? trim($raw) : $raw;

		$list = $spec->listClass();
		if ($list !== null) {
			return $this->list($spec, $list, $value);
		}

		if ($value === '' && !$spec->meta->allowEmpty) {
			throw new UsageError($spec->positional
				? "<{$spec->placeholder()}> needs a value"
				: "--{$spec->cliName} is required and must have a value");
		}

		return $this->scalar($spec, $value);
	}

	/** @param class-string<ValueList> $list */
	private function list(ValueSpec $spec, string $list, string $value): ValueList
	{
		// Stray separators and surrounding whitespace are dropped rather than becoming empty
		// elements — `--ids=1, ,2` means two ids, not three.
		$parts = array_values(array_filter(
			array_map('trim', explode($spec->meta->separator, $value)),
			static fn(string $p): bool => $p !== '',
		));

		$element = $list::elementType();
		$values = array_map(fn(string $part): mixed => $this->element($spec, $element, $part), $parts);

		$this->assertCount($spec, count($values));

		return $list::of($values);
	}

	/** @param 'string'|'int'|'float'|class-string<BackedEnum> $element */
	private function element(ValueSpec $spec, string $element, string $part): mixed
	{
		if ($element === 'int') {
			return $this->integer($spec, $part);
		}
		if ($element === 'float') {
			return $this->float($spec, $part);
		}
		if ($element !== 'string' && is_a($element, BackedEnum::class, true)) {
			return $this->enum($spec, $element, $part);
		}

		$this->assertPattern($spec, $part);

		return $part;
	}

	private function scalar(ValueSpec $spec, string $value): mixed
	{
		$enum = $spec->enumClass();
		if ($enum !== null) {
			return $this->enum($spec, $enum, $value);
		}

		if ($spec->typeName !== null && !$spec->isBuiltin && is_a($spec->typeName, DateTimeInterface::class, true)) {
			return $this->date($spec, $value);
		}

		return match ($spec->typeName) {
			'int' => $this->integer($spec, $value),
			'float' => $this->float($spec, $value),
			default => $this->string($spec, $value),
		};
	}

	private function string(ValueSpec $spec, string $value): string
	{
		$this->assertPattern($spec, $value);

		return $value;
	}

	private function integer(ValueSpec $spec, string $value): int
	{
		$this->assertPattern($spec, $value);

		$int = filter_var($value, FILTER_VALIDATE_INT);
		if ($int === false) {
			throw new UsageError($this->describe($spec) . ' must be a whole number. Got ' . $this->quote($value) . '.' . $this->hint($spec));
		}

		// A lower bound of exactly 1 is the common "this is an id" case, and saying so reads better
		// than reciting the range.
		if ($spec->meta->min === 1 && $spec->meta->max === null && $int < 1) {
			throw new UsageError($this->describe($spec) . ' must be a positive integer. Got ' . $this->quote($value) . '.' . $this->hint($spec));
		}
		if ($spec->meta->min !== null && $int < $spec->meta->min) {
			throw new UsageError($this->describe($spec) . " must be at least {$spec->meta->min}. Got " . $this->quote($value) . '.' . $this->hint($spec));
		}
		if ($spec->meta->max !== null && $int > $spec->meta->max) {
			throw new UsageError($this->describe($spec) . " must be at most {$spec->meta->max}. Got " . $this->quote($value) . '.' . $this->hint($spec));
		}

		return $int;
	}

	private function float(ValueSpec $spec, string $value): float
	{
		$this->assertPattern($spec, $value);

		$float = filter_var($value, FILTER_VALIDATE_FLOAT);
		if ($float === false) {
			throw new UsageError($this->describe($spec) . ' must be a number. Got ' . $this->quote($value) . '.' . $this->hint($spec));
		}

		return $float;
	}

	/** @param class-string<BackedEnum> $enum */
	private function enum(ValueSpec $spec, string $enum, string $value): BackedEnum
	{
		$case = $enum::tryFrom($value);
		if ($case !== null) {
			return $case;
		}

		// Generated from the cases, so it can never list four of five.
		$allowed = implode(', ', array_map(
			static fn(BackedEnum $c): string => (string) $c->value,
			$enum::cases(),
		));

		throw new UsageError('invalid ' . $this->describe($spec) . ' ' . $this->quote($value) . ". Allowed: {$allowed}." . $this->hint($spec));
	}

	private function date(ValueSpec $spec, string $value): DateTimeImmutable
	{
		$this->assertPattern($spec, $value);

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			throw new UsageError('cannot parse ' . $this->describe($spec) . ': ' . $e->getMessage());
		}
	}

	private function assertPattern(ValueSpec $spec, string $value): void
	{
		if ($spec->meta->pattern === null || preg_match($spec->meta->pattern, $value) === 1) {
			return;
		}

		throw new UsageError(sprintf(
			'%s must be <%s>. Got %s.%s',
			$this->describe($spec),
			$spec->placeholder(),
			$this->quote($value),
			$this->hint($spec),
		));
	}

	private function assertCount(ValueSpec $spec, int $count): void
	{
		if ($spec->meta instanceof Arg && $spec->meta->required && $count === 0) {
			throw new UsageError("at least one <{$spec->placeholder()}> is required");
		}
		if ($spec->meta->min !== null && $count < $spec->meta->min) {
			throw new UsageError("at least {$spec->meta->min} <{$spec->placeholder()}> are required");
		}
		if ($spec->meta->max !== null && $count > $spec->meta->max) {
			throw new UsageError("at most {$spec->meta->max} <{$spec->placeholder()}> are accepted");
		}
	}

	private function describe(ValueSpec $spec): string
	{
		return $spec->positional ? "<{$spec->placeholder()}>" : "--{$spec->cliName}";
	}

	private function quote(string $value): string
	{
		return "'{$value}'";
	}

	private function hint(ValueSpec $spec): string
	{
		return $spec->meta->hint === null ? '' : ' ' . $spec->meta->hint;
	}
}
