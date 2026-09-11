<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Reads a command set's declarations. The whole "single source of truth" claim lives here: nothing
 * below this class knows a command name or an option name that was not taken from a PHP symbol.
 *
 * Every mistake it can detect, it throws on — a command method without #[Command], a name that
 * cannot become a flag, a global colliding with a parameter. A declaration error must fail on the
 * first run, loudly, rather than turn into help text that lies.
 */
final class Introspector
{
	public function set(object $set): SetInfo
	{
		$class = new ReflectionClass($set);

		$cli = $this->attribute($class->getAttributes(Cli::class));
		if ($cli === null) {
			throw new LogicException($class->getName() . ' is missing #[Cli]. A command set declares its own summary.');
		}

		$globals = $this->globals($class);
		$commands = $this->commands($class, $globals);

		if ($commands === []) {
			throw new LogicException($class->getName() . ' declares no #[Command] method.');
		}
		if ($cli->single && count($commands) > 1) {
			throw new LogicException(sprintf(
				'%s is #[Cli(single: true)] but declares %d commands: %s. A single set holds exactly one.',
				$class->getName(),
				count($commands),
				implode(', ', array_keys($commands)),
			));
		}

		$catches = [];
		foreach ($class->getAttributes(CatchAs::class) as $attribute) {
			$catches[] = $attribute->newInstance();
		}

		return new SetInfo($cli, $commands, $globals, $catches);
	}

	/**
	 * @param ReflectionClass<object> $class
	 * @param list<ValueSpec> $globals
	 * @return array<string, CommandInfo>
	 */
	private function commands(ReflectionClass $class, array $globals): array
	{
		/** @var list<string> $globalNames */
		$globalNames = array_column($globals, 'cliName');
		$commands = [];

		foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			$meta = $this->attribute($method->getAttributes(Command::class));

			if ($meta === null) {
				// A method that looks like a command but is not declared as one is almost certainly
				// a forgotten attribute, and silence would read as "my command vanished".
				if (str_starts_with($method->getName(), 'command')) {
					throw new LogicException(sprintf(
						'%s::%s() is named like a command but carries no #[Command].',
						$class->getName(),
						$method->getName(),
					));
				}
				continue;
			}

			$name = Name::ofCommand($method->getName());
			$params = $this->params($method, $globalNames);

			$commands[$name] = new CommandInfo($name, $method, $meta, $params);
		}

		return $commands;
	}

	/**
	 * @param list<string> $globalNames
	 * @return list<ValueSpec>
	 */
	private function params(ReflectionMethod $method, array $globalNames): array
	{
		$specs = [];
		$seenVariadic = false;

		foreach ($method->getParameters() as $parameter) {
			$meta = $this->attribute(
				$parameter->getAttributes(Param::class, ReflectionAttribute::IS_INSTANCEOF),
			) ?? new Opt();

			$where = sprintf('%s::%s($%s)', $method->getDeclaringClass()->getName(), $method->getName(), $parameter->getName());

			if (!Name::isValid($parameter->getName())) {
				throw new LogicException("{$where}: '{$parameter->getName()}' cannot become a command-line name. Use plain camelCase.");
			}

			$cliName = Name::toKebab($parameter->getName());
			$positional = $meta instanceof Arg;

			if (!$positional && in_array($cliName, $globalNames, true)) {
				throw new LogicException("{$where}: --{$cliName} is already a global of this set.");
			}
			if ($parameter->isVariadic() && !$positional) {
				throw new LogicException("{$where}: a variadic parameter must be #[Arg], not an option.");
			}
			if ($seenVariadic) {
				throw new LogicException("{$where}: nothing may follow a variadic parameter.");
			}
			$seenVariadic = $parameter->isVariadic();

			$specs[] = $this->spec($parameter, $meta, $cliName, $positional);
		}

		return $specs;
	}

	private function spec(ReflectionParameter $parameter, Param $meta, string $cliName, bool $positional): ValueSpec
	{
		$type = $parameter->getType();
		$named = $type instanceof ReflectionNamedType ? $type : null;

		return new ValueSpec(
			phpName: $parameter->getName(),
			cliName: $cliName,
			meta: $meta,
			positional: $positional,
			variadic: $parameter->isVariadic(),
			hasDefault: $parameter->isDefaultValueAvailable(),
			default: $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
			typeName: $named?->getName(),
			allowsNull: $named?->allowsNull() ?? true,
			isBuiltin: $named?->isBuiltin() ?? true,
		);
	}

	/**
	 * Set-wide options, declared as public properties carrying #[Opt].
	 *
	 * A property rather than a repeated parameter, because these are the options that genuinely
	 * belong to the script rather than to one command, and repeating them on fifteen signatures
	 * would be the duplication this package exists to remove.
	 *
	 * @param ReflectionClass<object> $class
	 * @return list<ValueSpec>
	 */
	private function globals(ReflectionClass $class): array
	{
		$globals = [];

		foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
			$meta = $this->attribute($property->getAttributes(Opt::class));
			if ($meta === null) {
				continue;
			}

			$where = $class->getName() . '::$' . $property->getName();
			if (!Name::isValid($property->getName())) {
				throw new LogicException("{$where}: cannot become a command-line name. Use plain camelCase.");
			}
			if (!$property->hasDefaultValue()) {
				throw new LogicException("{$where}: a global #[Opt] needs a default — it is what applies when the flag is absent.");
			}

			$type = $property->getType();
			$named = $type instanceof ReflectionNamedType ? $type : null;

			$globals[] = new ValueSpec(
				phpName: $property->getName(),
				cliName: Name::toKebab($property->getName()),
				meta: $meta,
				positional: false,
				variadic: false,
				hasDefault: true,
				default: $property->getDefaultValue(),
				typeName: $named?->getName(),
				allowsNull: $named?->allowsNull() ?? true,
				isBuiltin: $named?->isBuiltin() ?? true,
				property: $property,
			);
		}

		return $globals;
	}

	/**
	 * The first instance of an attribute, or null.
	 *
	 * @template T of object
	 * @param array<ReflectionAttribute<T>> $attributes
	 * @return T|null
	 */
	private function attribute(array $attributes): ?object
	{
		return $attributes === [] ? null : $attributes[0]->newInstance();
	}
}
