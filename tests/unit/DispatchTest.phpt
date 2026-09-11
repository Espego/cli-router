<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\Runner;
use Espego\CliRouter\Tests\DemoSet;
use Espego\CliRouter\Tests\Trace;
use Espego\CliRouter\UsageError;
use Tester\Assert;

/** @return array{int, string, string} */
function run(array $argv): array
{
	Trace::$seen = [];
	$out = new BufferedOutput();
	$code = (new DemoSet())->handle(['demo.php', ...$argv], $out);

	return [$code, $out->out, $out->err];
}

// --- the option a command does not take ---------------------------------------------------

// A filter that silently fails to apply returns everything, which is what a filter matching
// everything returns — so there is nothing in the output to notice. It must be an error.
[$code, $stdout, $err] = run(['contracts', '--msa=IC01']);
Assert::same(1, $code);
Assert::same('', $stdout);
// --confirm is absent from the list because `contracts` carries no #[Mutates] — the gate shows up
// in the advice as well as in the rejection.
Assert::same(
	"error: unknown option --msa for 'contracts'. Accepted: --help, --nr, --raw. Run: php demo.php help\n",
	$err,
);

// Every offender is named at once; fixing them one run at a time is the slow way to find out.
[, , $err] = run(['contracts', '--typo=1', '--nr=X', '--other']);
Assert::contains('unknown options --typo, --other', $err);

// An unknown command names what does exist.
[$code, , $err] = run(['contract']);
Assert::same(1, $code);
Assert::contains("unknown command 'contract'. Accepted: env, contracts,", $err);

// --- globals --------------------------------------------------------------------------------

// A global applies to every command...
Assert::same(true, json_decode(run(['env', '--raw'])[1], true)['raw']);

// ...except one gated by an attribute the command does not carry. --confirm belongs to writes.
[, , $err] = run(['env', '--confirm']);
Assert::contains('unknown option --confirm', $err);

// And is accepted on one that does.
Assert::same(0, run(['save', '--what=x', '--confirm'])[0]);

// --- exit codes and stream ordering -----------------------------------------------------------

// The dry-run notice frames the preview, so it must precede it — on the other stream.
[$code, $stdout, $err] = run(['save', '--what=thing']);
Assert::same(2, $code);
Assert::same("trace: save\ndry run — nothing written.\n", $err);
Assert::same(['what' => 'thing'], json_decode($stdout, true));

// A warning qualifies what was printed, so it must follow it.
[$code, $stdout, $err] = run(['save', '--what=thing', '--confirm']);
Assert::same(0, $code);
Assert::same(['saved' => 'thing'], json_decode($stdout, true));
Assert::same("trace: save\nwarning: could not verify by reading it back.\n", $err);

// A command may name its own exit code, and pre-rendered text is emitted verbatim.
[$code, $stdout] = run(['report', '--code=3']);
Assert::same(3, $code);
Assert::same("a report\n", $stdout);

// --- exceptions ------------------------------------------------------------------------------

// A declared exception is a decision: one line, a documented exit code, no stack trace.
[$code, , $err] = run(['save', '--what=boom', '--confirm']);
Assert::same(4, $code);
Assert::same("trace: save\nrefused: that one is already saved.\n", $err);

// An undeclared one is a fault and stays a fault — turning it into a tidy exit code is how a
// broken deployment comes to look like a clean refusal.
Assert::exception(
	static fn() => (new DemoSet())->handle(['demo.php', 'fault'], new BufferedOutput()),
	OutOfRangeException::class,
	'a genuine fault',
);

// fail() works from any depth, and reports as bad usage rather than as a crash.
[$code, , $err] = run(['nope']);
Assert::same(1, $code);
Assert::same("trace: nope\nerror: refused by a helper three frames down\n", $err);

// --- middleware ------------------------------------------------------------------------------

// It runs for a command...
run(['env']);
Assert::same(['env'], Trace::$seen);

// ...and not for an invocation that never reached one. A banner announcing which environment you
// are pointed at is noise on a typo, and worse, it implies something ran.
run(['contracts', '--msa=X']);
Assert::same([], Trace::$seen);
run(['help']);
Assert::same([], Trace::$seen);

// --- the command knows its own name -----------------------------------------------------------

// So a message can name it without a string literal that duplicates the method name.
Assert::same('rename', json_decode(run(['rename'])[1], true)['command']);

// --- calling a command directly, with named arguments and no argv --------------------------------

$result = (new DemoSet())->commandTyped(colour: Espego\CliRouter\Tests\Colour::Blue, count: 3);
Assert::same(0, $result->exitCode);
Assert::same('blue', $result->json['colour']);

// A helper that fails throws, so it is assertable without running a process.
Assert::exception(
	static fn() => (new DemoSet())->commandNope(),
	UsageError::class,
	'refused by a helper three frames down',
);

// --- an internal error is not the user's fault --------------------------------------------------

Assert::same(70, Runner::EXIT_INTERNAL);
