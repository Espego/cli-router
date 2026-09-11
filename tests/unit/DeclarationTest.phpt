<?php

declare(strict_types=1);

/**
 * Declarations that cannot work must fail at introspection, naming the symbol at fault.
 *
 * The alternative is what these used to do: fall through to the string branch and quietly become
 * something the signature never asked for. A declaration and a runtime that disagree is the exact
 * failure this package exists to prevent, so it must not be reachable from inside it either.
 */

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\CatchAs;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\Introspector;
use Espego\CliRouter\Opt;
use Tester\Assert;

/**
 * $why is the label a reader needs; Assert::exception's fourth argument is an exception CODE, so
 * it is kept out of the call and put where a failure will actually show it.
 *
 * @param callable(): object $make
 */
function rejects(string $why, callable $make, string $expected): void
{
	try {
		(new Introspector())->set($make());
	} catch (LogicException $e) {
		Assert::match($expected, $e->getMessage(), "rejecting {$why}");

		return;
	}

	Assert::fail("{$why}: expected a LogicException, none was thrown");
}

rejects('a union type', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] string|int $v = ''): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~Union and intersection types are not supported~');

rejects('no type at all', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] $v = ''): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~needs a type~');

rejects('a bare array', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] array $v = []): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~use a ValueList~');

rejects('an unsupported class', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] ?stdClass $v = null): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~not a supported option type~');

rejects('a mutable date', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] ?DateTime $v = null): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~use DateTimeImmutable~');

rejects('a by-reference parameter', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] string &$v = ''): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~cannot be by-reference~');

rejects('an empty separator', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', separator: '')] string $v = ''): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~separator cannot be empty~');

rejects('a malformed pattern', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', pattern: '/unterminated')] string $v = ''): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~not a valid regular expression~');

rejects('a non-public global', static fn() => new #[Cli('x')] class extends Commands {
	#[Opt('hidden')]
	private bool $raw = false;

	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~must be public~');

rejects('a static global', static fn() => new #[Cli('x')] class extends Commands {
	#[Opt('shared')]
	public static bool $raw = false;

	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~cannot be static~');

rejects('a CatchAs that is not throwable', static fn() => new #[Cli('x')] #[CatchAs(stdClass::class, exitCode: 4)] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~is not a Throwable~');

// And the supported types are genuinely accepted, so the gate is not simply refusing everything.
$ok = new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(
		#[Opt('s')] string $s = '',
		#[Opt('i')] int $i = 0,
		#[Opt('f')] float $f = 0.0,
		#[Opt('b')] bool $b = false,
		#[Opt('e')] ?Espego\CliRouter\Tests\Colour $e = null,
		#[Opt('l')] ?Espego\CliRouter\StringList $l = null,
		#[Opt('d')] ?DateTimeImmutable $d = null,
	): CommandResult {
		return CommandResult::nothing();
	}
};
Assert::same(['a'], (new Introspector())->set($ok)->names());
