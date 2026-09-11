<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\ArgumentParser;
use Tester\Assert;

$parser = new ArgumentParser();

// Command plus a valued option.
Assert::same(
	['args' => ['events'], 'opts' => ['msa' => 'IC0012345678'], 'duplicates' => []],
	$parser->parse(['events', '--msa=IC0012345678']),
);

// A bare flag is boolean true, not the empty string. Everything above this layer depends on the
// difference: it is what lets a value-taking option refuse `--title` while accepting `--title=`.
$r = $parser->parse(['add-event', '--confirm', '--raw']);
Assert::true($r['opts']['confirm']);
Assert::true($r['opts']['raw']);

// Positional order is preserved and options are stripped out of it.
Assert::same(['a', 'b', 'c'], $parser->parse(['a', '--x=1', 'b', '--y', 'c'])['args']);

// Only the FIRST '=' splits, so a value may contain '=' (and ':', spaces, diacritics).
Assert::same(
	'ukončení k 31. 12. 2026, cena=beze změny',
	$parser->parse(['--note=ukončení k 31. 12. 2026, cena=beze změny'])['opts']['note'],
);

// An explicitly empty value stays an empty string — distinct from a bare flag's `true`.
Assert::same('', $parser->parse(['--note='])['opts']['note']);

// A bare '--' ends option parsing: everything after it is positional, whatever it looks like.
// Without this there is no way at all to pass a path beginning with '--'.
Assert::same(['args' => [], 'opts' => [], 'duplicates' => []], $parser->parse(['--']));
Assert::same(['--x', '-y', 'z'], $parser->parse(['--', '--x', '-y', 'z'])['args']);
Assert::same(['flag' => true], $parser->parse(['--flag', '--', '--x'])['opts']);
Assert::same(['--x'], $parser->parse(['--flag', '--', '--x'])['args']);

// Values that look like options are kept verbatim.
Assert::same('--not-a-flag', $parser->parse(['--note=--not-a-flag'])['opts']['note']);

// A repeated option is REPORTED, not resolved. Quietly keeping one of the two is what let a
// malformed `--confirm=false` be rescued by a later bare `--confirm`; the runner rejects the run.
// Multi-value options take a separator, so a repeat is a mistake either way.
Assert::same(['msa'], $parser->parse(['--msa=first', '--msa=second'])['duplicates']);
Assert::same([], $parser->parse(['--msa=one', '--nr=two'])['duplicates']);

// Each repeated name is listed once, however many times it appeared, and in the order first seen.
Assert::same(['a', 'b'], $parser->parse(['--a=1', '--b=1', '--a=2', '--b=2', '--a=3'])['duplicates']);

// A name repeated only after '--' is a positional, not a duplicate.
Assert::same([], $parser->parse(['--a=1', '--', '--a=2'])['duplicates']);

Assert::same(['args' => [], 'opts' => [], 'duplicates' => []], $parser->parse([]));
