<?php

declare(strict_types=1);

/**
 * The help is the package's contract with a human, and it is generated — so it is asserted whole,
 * byte for byte, against a checked-in file. Substring assertions pass just as happily when a
 * command has silently vanished, a column has moved, or the output has been truncated.
 */

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\Tests\DemoSet;
use Tester\Assert;

function rendered(string ...$argv): string
{
	$out = new BufferedOutput();
	$code = (new DemoSet())->handle(['demo.php', ...$argv], $out);
	Assert::same(0, $code);
	Assert::same('', $out->err, 'help belongs on stdout: `--help | less` must work');

	return $out->out;
}

// The whole overview, exactly.
Assert::matchFile(__DIR__ . '/DemoHelp.expect', rendered('help'));

// `help` and `--help` are the same page.
Assert::same(rendered('help'), rendered('--help'));

// A global gated by a marker attribute applies to some commands and not others, so it must not be
// advertised on the overview as though it applied everywhere.
Assert::notContains('--confirm', rendered('help'));

// It does appear on a command that takes it...
Assert::contains('--confirm', rendered('help', 'save'));

// ...and not on one that does not.
Assert::notContains('--confirm', rendered('help', 'env'));

// Required and optional are read from the signature, so the brackets cannot contradict the code.
Assert::contains('--colour=<red|green|blue>', rendered('help'));
Assert::contains('[--count=<n>]', rendered('help'));

// Every line stays inside the declared width. Counted in characters: a byte count wraps a Czech
// line early and misaligns the column after it.
foreach (explode("\n", rendered('help')) as $line) {
	Assert::true(mb_strlen($line) <= 92, 'help line past the margin: ' . $line);
}
