<?php

declare(strict_types=1);

/**
 * The three ways a command set is worth testing. The distributed worked example copies this exact
 * source as a pattern: consumers adapt its bootstrap, imports, fixtures, commands and assertions.
 */

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\StringList;
use Espego\CliRouter\Tests\Documentation\NoteSet;
use Espego\CliRouter\Tests\Documentation\NoteStore;
use Espego\CliRouter\Tests\Documentation\Priority;
use Tester\Assert;

/** Explicit test data, so this case depends on no hidden or shared fixture state. */
function set(): NoteSet
{
	return new NoteSet(new NoteStore([
		['id' => 7, 'text' => 'Renew the domain', 'priority' => 'high', 'tags' => ['ops'], 'detail' => 'Before October.', 'due' => '2026-10-01'],
	]));
}

/** @return array{int, string, string} exit code, stdout, stderr */
function cli(string ...$argv): array
{
	return set()->handleBuffered(['notes.php', ...$argv]);
}


// ── 1. The body, called directly with named arguments. No process, no argv, no parsing. ──

$result = set()->commandAdd('Book the room', priority: Priority::High, tag: StringList::of(['ops']));
Assert::same(2, $result->exitCode, 'a write without --confirm previews and says so in the exit code');
Assert::same(['preview — nothing written. Repeat with --confirm.'], $result->notices);

$confirmed = set();
$confirmed->confirm = true;
Assert::same(0, $confirmed->commandAdd('Book the room')->exitCode);


// ── 2. The whole CLI, through handle(), asserting the exit code and the two streams apart. ──

[$code, $out, $err] = cli('show', '7');
Assert::same(0, $code);
Assert::same('', $err);
Assert::contains('"text": "Renew the domain"', $out);

// An unknown option is answered before a command body exists.
[$code, $out, $err] = cli('show', '7', '--verbos');
Assert::same(1, $code);
Assert::same('', $out, 'a usage error writes nothing to stdout');
Assert::contains('unknown option --verbos', $err);

// A mapped exception is the store's considered no: its own exit code, one line, no stack trace.
[$code, , $err] = cli('show', '99');
Assert::same(4, $code);
Assert::same("rejected: there is no note 99.\n", $err);

// fail(), raised from the body for a rule no declaration could carry.
[$code, , $err] = cli('edit', '7');
Assert::same(1, $code);
Assert::contains('name at least one of', $err);

// --confirm exists only on commands carrying #[Writes].
[$code, , $err] = cli('list', '--confirm');
Assert::same(1, $code);
Assert::contains('unknown option --confirm', $err);


// ── 3. Not given is not the same as given empty. ──

// Called directly: an empty string passed for $detail is a change; omitting it is not.
Assert::same(['id' => 7, 'changes' => ['detail' => '']], set()->commandEdit(7, detail: '')->json);
Assert::same(['id' => 7, 'changes' => ['text' => 'Renew it']], set()->commandEdit(7, text: 'Renew it')->json);

// Through argv, the same distinction: `--detail=` is a value, so the field is cleared.
[$code, $out] = cli('edit', '7', '--detail=', '--confirm');
Assert::same(0, $code);
Assert::contains('"detail": ""', $out);

// Omitting it leaves the field alone. Only `?string $detail = null` tells the two apart —
// `string $detail = ''` would refuse `--detail=` outright.
[$code, $out] = cli('edit', '7', '--text=Renew it', '--confirm');
Assert::same(0, $code);
Assert::contains('"detail": "Before October."', $out);


// ── 4. The help, byte for byte, against a checked-in file. ──

// A substring assertion is not a substitute: it passes just as happily when a command has
// silently vanished, a column has moved, or the output has been truncated.
$pages = set()->helpPages('notes.php');
[$code, $out, $err] = cli('help');
Assert::same(0, $code);
Assert::same('', $err, 'help belongs on stdout, so `--help | less` works');
Assert::matchFile(__DIR__ . '/DocumentationExampleHelp.expect', $pages['overview']);
Assert::same($pages['overview'], $out);
Assert::same(['overview', 'list', 'show', 'add', 'edit'], array_keys($pages));

// `help` and `--help` are the same page.
Assert::same($out, cli('--help')[1]);
