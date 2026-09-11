<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/**
 * The one mapping between PHP identifiers and command-line names.
 *
 * `commandNoteDone` is `note-done`; `$intendedEnv` is `--intended-env`. It is total for the names
 * that occur in practice, and `isLossless()` exists so the introspector can refuse a name where it
 * is not — consecutive capitals, a digit boundary, an underscore — rather than let the help
 * quietly describe a flag nobody can type.
 */
final class Name
{
	public static function toKebab(string $identifier): string
	{
		$kebab = preg_replace('/(?<!^)[A-Z]/', '-$0', $identifier);

		return strtolower((string) $kebab);
	}

	public static function toCamel(string $kebab): string
	{
		return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $kebab))));
	}

	/** A method name minus its optional `command` prefix: `commandNoteDone` -> `note-done`. */
	public static function ofCommand(string $method): string
	{
		if (str_starts_with($method, 'command') && strlen($method) > 7) {
			$method = lcfirst(substr($method, 7));
		}

		return self::toKebab($method);
	}

	/**
	 * May this identifier become a command-line name?
	 *
	 * Two conditions, and both are needed. It must be plain camelCase — an underscore round-trips
	 * perfectly well and would yield `--some_thing`, a flag nobody would guess. And the kebab form
	 * must convert back to the identifier it came from, so the mapping stays reversible.
	 */
	public static function isValid(string $identifier): bool
	{
		if (preg_match('/^[a-z][a-zA-Z0-9]*$/', $identifier) !== 1) {
			return false;
		}

		return self::toCamel(self::toKebab($identifier)) === $identifier;
	}
}
