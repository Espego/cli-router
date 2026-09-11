<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\Tests\DemoSet;
use Tester\Assert;

function help(array $argv): string
{
	$out = new BufferedOutput();
	(new DemoSet())->handle(['demo.php', ...$argv], $out);

	return $out->out;
}

$whole = help(['help']);

// Everything in the command list is generated, so it cannot list four of five.
foreach (['env', 'contracts', 'events', 'typed', 'report', 'save', 'rename'] as $command) {
	Assert::contains($command, $whole);
}

// Group headings come from #[Cli(groups:)] and keep their declared order.
Assert::true(strpos($whole, 'Read:') < strpos($whole, 'Write:'));

// The set's own prose survives verbatim.
Assert::contains('Demo CLI.', $whole);
Assert::contains('Footer prose.', $whole);

// Globals are listed once, on the overview.
Assert::contains('--raw', $whole);
Assert::contains('--help', $whole);

// A list option says so, or `--msa=<msaNr>` reads as accepting exactly one.
Assert::contains('--msa=<msaNr>[,<msaNr>...]', $whole);

// A long placeholder does not get repeated — the reader has already seen the cases once.
Assert::contains('--colours=<red|green|blue>[,...]', $whole);

// An optional option is bracketed, a required one is not. Both are read from the signature, so
// the help cannot claim something is required when the default says otherwise.
Assert::contains('[--nr=<nr>]', $whole);
Assert::contains('--colour=<red|green|blue>', $whole);

// --- per-command help ---------------------------------------------------------------------

$typed = help(['help', 'typed']);
Assert::contains('Typed values.', $typed);
Assert::contains('Required.', $typed);
Assert::contains('--count=<n>', $typed);

// An enum's cases are printed from ::cases(), never retyped.
Assert::contains('red|green|blue', $typed);

// `<command> --help` is answered BEFORE validation, so it works without the options the command
// would otherwise demand — which is the whole reason to ask for it.
Assert::same($typed, help(['typed', '--help']));

// Asking about a command that does not exist is an error, not an empty page.
$out = new BufferedOutput();
$code = (new DemoSet())->handle(['demo.php', 'help', 'nonsense'], $out);
Assert::same(1, $code);
Assert::same('', $out->out);
Assert::contains("unknown command 'nonsense'", $out->err);

// --- wrapping ------------------------------------------------------------------------------

// Counted in characters, not bytes. A byte-counted wrap breaks a Czech line early by however
// many multi-byte characters it happens to carry, and misaligns the column after it.
foreach (explode("\n", $whole) as $line) {
	Assert::true(mb_strlen($line) <= 100, 'a help line ran past the margin: ' . $line);
}
