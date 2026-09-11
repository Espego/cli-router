<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\ArgumentParser;
use Tester\Assert;

$parser = new ArgumentParser();

// Command plus a valued option.
Assert::same(
	['args' => ['events'], 'opts' => ['msa' => 'IC0012345678']],
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

// A lone '--' carries no name and is ignored rather than creating an empty-string key.
Assert::same(['args' => [], 'opts' => []], $parser->parse(['--']));

// Values that look like options are kept verbatim.
Assert::same('--not-a-flag', $parser->parse(['--note=--not-a-flag'])['opts']['note']);

// A repeated option takes the last occurrence.
Assert::same('second', $parser->parse(['--msa=first', '--msa=second'])['opts']['msa']);

Assert::same(['args' => [], 'opts' => []], $parser->parse([]));
