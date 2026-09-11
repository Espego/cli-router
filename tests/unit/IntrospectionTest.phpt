<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\Arg;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\Introspector;
use Espego\CliRouter\Opt;
use Tester\Assert;

/**
 * Every declaration mistake fails loudly on the first run. A CLI whose help quietly disagrees with
 * its code is the failure this package exists to remove, so it must not be reachable by accident.
 */
function introspect(object $set): void
{
	(new Introspector())->set($set);
}

// A set says what it is.
$noCli = new class extends Commands {
	#[Command('x')]
	public function commandX(): CommandResult
	{
		return CommandResult::nothing();
	}
};
Assert::exception(static fn() => introspect($noCli), LogicException::class, '~is missing #\[Cli\]~');

// A set has at least one command.
$empty = new #[Cli('empty')] class extends Commands {};
Assert::exception(static fn() => introspect($empty), LogicException::class, '~declares no #\[Command\]~');

// A method named like a command but not declared as one is a forgotten attribute, and silence
// would read as "my command vanished".
$forgotten = new #[Cli('x')] class extends Commands {
	#[Command('real')]
	public function commandReal(): CommandResult
	{
		return CommandResult::nothing();
	}

	public function commandForgotten(): CommandResult
	{
		return CommandResult::nothing();
	}
};
Assert::exception(static fn() => introspect($forgotten), LogicException::class, '~carries no #\[Command\]~');

// A public helper without the attribute is NOT a command, and is not mistaken for one.
$withHelper = new #[Cli('x')] class extends Commands {
	#[Command('real')]
	public function commandReal(): CommandResult
	{
		return CommandResult::nothing();
	}

	public function helper(): string
	{
		return 'not a command';
	}
};
Assert::same(['real'], (new Introspector())->set($withHelper)->names());

// `single: true` means exactly one.
$twoSingles = new #[Cli('x', single: true)] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}

	#[Command('b')]
	public function commandB(): CommandResult
	{
		return CommandResult::nothing();
	}
};
Assert::exception(static fn() => introspect($twoSingles), LogicException::class, '~single: true~');

// A parameter name that cannot become a flag is refused rather than silently mangled.
$badName = new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(
		#[Opt('bad')] string $some_thing = '',
	): CommandResult {
		return CommandResult::nothing();
	}
};
Assert::exception(static fn() => introspect($badName), LogicException::class, '~cannot become a command-line name~');

// An option colliding with a global would make one of them unreachable.
$collision = new #[Cli('x')] class extends Commands {
	#[Opt('global')]
	public bool $raw = false;

	#[Command('a')]
	public function commandA(
		#[Opt('local')] bool $raw = false,
	): CommandResult {
		return CommandResult::nothing();
	}
};
Assert::exception(static fn() => introspect($collision), LogicException::class, '~already a global~');

// A variadic collects positionals, so it cannot be an option.
$variadicOpt = new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(
		#[Opt('nope')] string ...$rest,
	): CommandResult {
		return CommandResult::nothing();
	}
};
Assert::exception(static fn() => introspect($variadicOpt), LogicException::class, '~must be #\[Arg\]~');

// A global needs a default: it is what applies when the flag is absent.
$noDefault = new #[Cli('x')] class extends Commands {
	#[Opt('global')]
	public bool $raw;

	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
};
Assert::exception(static fn() => introspect($noDefault), LogicException::class, '~needs a default~');
