<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\Name;
use Tester\Assert;

// A method is a command name with its `command` prefix removed, kebab-cased.
Assert::same('note-done', Name::ofCommand('commandNoteDone'));
Assert::same('add-event', Name::ofCommand('commandAddEvent'));
Assert::same('cancel-msa', Name::ofCommand('commandCancelMsa'));
Assert::same('env', Name::ofCommand('commandEnv'));

// A method that is not prefixed keeps its whole name — the prefix is a convention, not a rule.
Assert::same('check', Name::ofCommand('check'));

// `command` alone is not a prefix to strip; there would be nothing left.
Assert::same('command', Name::ofCommand('command'));

// Parameters map the same way, which is what makes --intended-env and $intendedEnv one fact.
Assert::same('intended-env', Name::toKebab('intendedEnv'));
Assert::same('due-before', Name::toKebab('dueBefore'));
Assert::same('no-logo', Name::toKebab('noLogo'));
Assert::same('nr', Name::toKebab('nr'));

// And back, so the CLI name can always be traced to the symbol it came from.
Assert::same('intendedEnv', Name::toCamel('intended-env'));
Assert::same('sidecarDir', Name::toCamel('sidecar-dir'));

// Plain camelCase is required. An underscore round-trips perfectly well and would yield
// `--some_thing`, a flag nobody would guess — so it is refused rather than silently accepted.
Assert::true(Name::isValid('intendedEnv'));
Assert::true(Name::isValid('nr'));
Assert::true(Name::isValid('opt2x'));
Assert::false(Name::isValid('some_thing'));
Assert::false(Name::isValid('Capitalised'));
Assert::false(Name::isValid(''));
