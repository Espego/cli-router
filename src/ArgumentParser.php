<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/**
 * Splits argv into positional arguments and `--key=value` / `--flag` options.
 *
 * Deliberately minimal: no short flags, no clustering, no `--opt value` (space-separated), no type
 * coercion. Everything above this layer is reflection; this layer only decides what is a name,
 * what is a value, and what is neither.
 *
 * A bare `--flag` yields `true`, distinct from `--flag=` which yields `''` — the caller can then
 * refuse "given but empty" separately from "given as a flag", which is the difference between
 * writing an empty note and refusing to.
 */
final class ArgumentParser
{
	/**
	 * @param list<string> $argv Arguments WITHOUT the script name.
	 * @return array{args: list<string>, opts: array<string, string|true>}
	 */
	public function parse(array $argv): array
	{
		$args = [];
		$opts = [];

		foreach ($argv as $arg) {
			if (!str_starts_with($arg, '--')) {
				$args[] = $arg;
				continue;
			}

			$body = substr($arg, 2);
			if ($body === '') {
				continue;
			}

			// Only the FIRST '=' splits, so a value may itself contain one.
			$eq = strpos($body, '=');
			if ($eq === false) {
				$opts[$body] = true;
			} else {
				$opts[substr($body, 0, $eq)] = substr($body, $eq + 1);
			}
		}

		return ['args' => $args, 'opts' => $opts];
	}
}
