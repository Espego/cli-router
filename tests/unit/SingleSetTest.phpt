<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\Tests\FileSet;
use Tester\Assert;

/** @return array{int, string, string} */
function run(array $argv): array
{
	$out = new BufferedOutput();
	$code = (new FileSet())->handle(['check.php', ...$argv], $out);

	return [$code, $out->out, $out->err];
}

// A single-command set needs no subcommand word: the first positional is already an argument.
[$code, $stdout] = run(['a.pdf', 'b.pdf']);
Assert::same(0, $code);
Assert::same(['a.pdf', 'b.pdf'], json_decode($stdout, true)['files']);

// Options mix freely with the variadic, in any order.
Assert::same(true, json_decode(run(['--json', 'a.pdf'])[1], true)['json']);
Assert::same(true, json_decode(run(['a.pdf', '--json'])[1], true)['json']);

// A variadic marked required insists on at least one value.
[$code, , $err] = run(['--json']);
Assert::same(1, $code);
Assert::same("error: at least one <file.pdf> is required\n", $err);

// Nothing at all is this script's failure, not its help — a wrapper that calls it with no
// arguments must not look like it did something.
[$code, $stdout, $err] = run([]);
Assert::same(1, $code);
Assert::same('', $stdout);
Assert::same("error: no file given.\n", $err);

// --help is still a success, and describes the one command.
[$code, $stdout] = run(['--help']);
Assert::same(0, $code);
Assert::contains('Check files.', $stdout);
Assert::contains('<file.pdf>', $stdout);

// A single set has no `help` verb, so the hint points at the flag that does exist.
[, , $err] = run(['a.pdf', '--nope']);
Assert::contains('Run: php check.php --help', $err);
Assert::notContains('Run: php check.php help', $err);
