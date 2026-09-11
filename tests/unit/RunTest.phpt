<?php

declare(strict_types=1);

/**
 * run() as a consumer actually calls it: a real process, real argv, real streams, a real exit code.
 *
 * Everything else here drives handle(), so without this the one method every entry script invokes
 * would be the only one never executed.
 */

require __DIR__ . '/../bootstrap.php';

use Tester\Assert;

/** @return array{int, string, string} */
function script(string ...$args): array
{
	$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/files.php');
	foreach ($args as $arg) {
		$command .= ' ' . escapeshellarg($arg);
	}

	$pipes = [];
	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	Assert::true(is_resource($process));

	$stdout = (string) stream_get_contents($pipes[1]);
	$stderr = (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return [proc_close($process), $stdout, $stderr];
}

// A successful run: payload on stdout, nothing on stderr, exit 0.
[$code, $stdout, $stderr] = script('a.pdf', 'b.pdf');
Assert::same(0, $code);
Assert::same('', $stderr);
Assert::same(['a.pdf', 'b.pdf'], json_decode($stdout, true)['files']);

// The exit code really is the process's, not just handle()'s return value.
[$code, $stdout, $stderr] = script();
Assert::same(1, $code);
Assert::same('', $stdout);
Assert::same("error: no file given.\n", $stderr);

// Help is a success and goes to stdout, so `--help | less` works and a wrapper does not mistake
// it for failure.
[$code, $stdout, $stderr] = script('--help');
Assert::same(0, $code);
Assert::same('', $stderr);
Assert::contains('Check files.', $stdout);

// An argument beginning with `--` survives the shell and `--`, arriving as a positional.
[$code, $stdout] = script('--', '--strange.pdf');
Assert::same(0, $code);
Assert::same(['--strange.pdf'], json_decode($stdout, true)['files']);

// Bad usage: diagnostics on stderr only, so a pipe on stdout stays clean.
[$code, $stdout, $stderr] = script('a.pdf', '--nope');
Assert::same(1, $code);
Assert::same('', $stdout);
Assert::contains('unknown option --nope', $stderr);
