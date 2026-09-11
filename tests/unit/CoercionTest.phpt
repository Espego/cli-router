<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\Tests\DemoSet;
use Tester\Assert;

/** @return array{int, string, string} exit code, stdout, stderr */
function run(array $argv): array
{
	$out = new BufferedOutput();
	$code = (new DemoSet())->handle(['demo.php', ...$argv], $out);

	return [$code, $out->out, $out->err];
}

function json(array $argv): mixed
{
	[, $stdout] = run($argv);

	return json_decode($stdout, true);
}

// --- the failures that used to be silently wrong ------------------------------------------

// A bare flag on a value-taking option. Casting `true` to a string is how `--ic` came to filter
// on company id 1; refusing it is the whole point of reading the declared type.
[$code, , $err] = run(['contracts', '--nr']);
Assert::same(1, $code);
Assert::same("error: --nr is required and must have a value\n", $err);

// A value on a flag. `--confirm=false` reads as "do not write" and used to write.
[$code, , $err] = run(['save', '--what=x', '--confirm=false']);
Assert::same(1, $code);
Assert::same("error: --confirm is a flag and takes no value\n", $err);

// An empty value is not a value, unless the declaration says it may be.
[$code, , $err] = run(['contracts', '--nr=']);
Assert::same(1, $code);
Assert::same("error: --nr is required and must have a value\n", $err);

// allowEmpty says otherwise, and trim: false leaves the value exactly as typed.
Assert::same('', json(['typed', '--colour=red', '--note='])['note']);
Assert::same('  padded  ', json(['typed', '--colour=red', '--note=  padded  '])['note']);

// --- types -------------------------------------------------------------------------------

// A backed enum needs no hand-written list, and cannot list four of five.
Assert::same('green', json(['typed', '--colour=green'])['colour']);
[$code, , $err] = run(['typed', '--colour=purple']);
Assert::same(1, $code);
Assert::same("error: invalid --colour 'purple'. Allowed: red, green, blue.\n", $err);

// A missing required option is reported as bad usage, never as an ArgumentCountError.
[$code, , $err] = run(['typed']);
Assert::same(1, $code);
Assert::same("error: --colour is required and must have a value\n", $err);

// Integers, with their bounds.
Assert::same(5, json(['typed', '--colour=red', '--count=5'])['count']);
[, , $err] = run(['typed', '--colour=red', '--count=abc']);
Assert::same("error: --count must be a whole number. Got 'abc'.\n", $err);
[, , $err] = run(['typed', '--colour=red', '--count=99']);
Assert::same("error: --count must be at most 10. Got '99'.\n", $err);

// Dates parse through DateTimeImmutable, and a parse failure is bad usage, not a stack trace.
Assert::same('2026-03-04', json(['typed', '--colour=red', '--at=2026-03-04'])['at']);
[$code, , $err] = run(['typed', '--colour=red', '--at=nonsense']);
Assert::same(1, $code);
Assert::contains('error: cannot parse --at:', $err);

// A pattern, and the hint that explains it.
Assert::same('2026-03-04', json(['typed', '--colour=red', '--on=2026-03-04'])['on']);
[, , $err] = run(['typed', '--colour=red', '--on=2026-3-4']);
Assert::same("error: --on must be <Y-m-d>. Got '2026-3-4'. No other format is accepted.\n", $err);

// --- lists -------------------------------------------------------------------------------
//
// Declared as StringList / IntList / an EnumList subclass, never as `array` plus a docblock: the
// element type is in the signature, which is the one place nothing can disagree with it.

Assert::same(['IC01', 'IC02', 'IC03'], json(['events', '--msa=IC01,IC02,IC03'])['msa']);

// Elements are coerced individually, so a list of ints really is a list of ints.
Assert::same([1, 2, 3], json(['events', '--msa=X', '--ids=1,2,3'])['ids']);

// And validated individually, naming the element that failed rather than the whole value.
[$code, , $err] = run(['events', '--msa=X', '--ids=1,x,3']);
Assert::same(1, $code);
Assert::same("error: --ids must be a whole number. Got 'x'.\n", $err);

// An enum list validates each element against the cases, generated as ever.
Assert::same(['red', 'blue'], json(['events', '--msa=X', '--colours=red,blue'])['colours']);
[, , $err] = run(['events', '--msa=X', '--colours=red,purple']);
Assert::same("error: invalid --colours 'purple'. Allowed: red, green, blue.\n", $err);

// Stray separators and whitespace are dropped rather than becoming empty elements.
Assert::same(['A', 'B'], json(['events', '--msa= A , ,B '])['msa']);

// Absent stays null; given-but-empty is an empty list. The two are different answers.
Assert::same(null, json(['events', '--msa=X'])['ids']);
Assert::same([], json(['events', '--msa=X', '--ids='])['ids']);

// A required list must still be given at all.
[$code, , $err] = run(['events']);
Assert::same(1, $code);
Assert::same("error: --msa is required and must have a value\n", $err);
