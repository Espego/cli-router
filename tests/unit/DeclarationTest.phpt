<?php

declare(strict_types=1);

/**
 * Declarations that cannot work must fail at introspection, naming the symbol at fault.
 *
 * The alternative is what these used to do: fall through to the string branch and quietly become
 * something the signature never asked for, or advertise a limit the coercer never reads. A
 * declaration and a runtime that disagree is the exact failure this package exists to prevent, so
 * it must not be reachable from inside it either.
 */

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\Arg;
use Espego\CliRouter\CatchAs;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\DeclarationError;
use Espego\CliRouter\EnumList;
use Espego\CliRouter\IntList;
use Espego\CliRouter\Introspector;
use Espego\CliRouter\Opt;
use Espego\CliRouter\StringList;
use Espego\CliRouter\Tests\Colour;
use Espego\CliRouter\Tests\Mutates;
use Espego\CliRouter\Tests\NoteTypeList;
use Espego\CliRouter\UsageError;
use Espego\CliRouter\ValueList;
use Espego\CliRouter\WhenEmpty;
use Tester\Assert;

/** A backed enum with nothing to accept: every value would be invalid, help would print `Allowed: `. */
enum Nothing: string
{
}

/** A ValueList whose element type is not one the coercer can produce. */
final class BadList implements ValueList
{
	/** @param list<mixed> $values */
	private function __construct(private readonly array $values)
	{
	}

	public static function elementType(): string
	{
		return 'bool';
	}

	/** @param list<mixed> $values */
	public static function of(array $values): static
	{
		return new static($values);
	}

	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->values);
	}

	public function count(): int
	{
		return count($this->values);
	}
}

/**
 * $why is the label a reader needs; Assert::exception's fourth argument is an exception CODE, so
 * it is kept out of the call and put where a failure will actually show it.
 *
 * DeclarationError rather than LogicException: InternalError is one too, and a router bug passing
 * as a refused declaration is exactly the confusion this suite exists to rule out.
 *
 * @param callable(): object $make
 */
function rejects(string $why, callable $make, string $expected): void
{
	try {
		(new Introspector())->set($make());
	} catch (DeclarationError $e) {
		Assert::match($expected, $e->getMessage(), "rejecting {$why}");

		return;
	}

	Assert::fail("{$why}: expected a DeclarationError, none was thrown");
}

// --- Types the coercer cannot produce --------------------------------------------------------

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

// --- A ValueList is trusted for its element type all the way into the help --------------------
//
// An unusable one used to reach ValueSpec::placeholder(), which assumes anything that is not
// 'string', 'int' or 'float' is a backed enum and calls ::cases() on it — so the declaration went
// through and `--help` died with `Error: Class "bool" not found`, a fatal while rendering.

rejects('a list of an impossible element', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] ?BadList $v = null): CommandResult
	{
		return CommandResult::nothing();
	}
}, "~elementType\\(\\) returns 'bool'~");

rejects('an abstract list', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] ?EnumList $v = null): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~is abstract~');

rejects('an enum with no cases', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] ?Nothing $v = null): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~declares no cases~');

// --- Patterns and separators ------------------------------------------------------------------

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
}, "~not a valid regular expression: No ending delimiter '/' found~");

rejects('a pattern that reasons in bytes', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', pattern: '/^\d+$/')] string $v = '1'): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~pattern needs the u modifier~');

rejects('a pattern on a type whose values are its cases', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', pattern: '/^y$/u')] bool $v = false): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~pattern cannot apply to bool~');

// --- Constraints that could never apply --------------------------------------------------------
//
// Silently inert is the worst of the three outcomes: the declaration reads as enforced, the help
// says nothing, and the coercer never looks. `#[Opt(min: 10, max: 1)] int $count = 0` ran fine and
// returned 0.

rejects('a default outside its own bounds', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', min: 10)] int $v = 0): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~the default 0 is outside the min/max~');

rejects('a default against its own pattern', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', pattern: '/^\d+$/u')] string $v = 'nope'): CommandResult
	{
		return CommandResult::nothing();
	}
}, "~the default 'nope' does not match its own pattern~");

rejects('min above max', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', min: 10, max: 1)] int $v = 10): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~min 10 is above max 1~');

rejects('bounds on something that is not a number', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', min: 1)] string $v = 'x'): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~min and max apply to int and float~');

rejects('a count on a scalar', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', minCount: 5)] string $v = 'x'): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~minCount applies to a list or a variadic~');

rejects('a count below one', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Arg('u', maxCount: 0)] string ...$v): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~maxCount is 0; it must be at least 1~');

rejects('minCount above maxCount', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Arg('u', minCount: 3, maxCount: 2)] string ...$v): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~minCount 3 is above maxCount 2~');

rejects('allowEmpty on a list', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', allowEmpty: true)] ?NoteTypeList $v = null): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~allowEmpty cannot apply to a list~');

rejects('required on something that is not variadic', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Arg('u', required: true)] string $v = ''): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~required applies to a variadic~');

rejects('a positional flag', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Arg('u')] bool $v = false): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~a positional cannot be bool~');

rejects('a list default whose elements break its bounds', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', min: 1)] IntList $v = new IntList([0])): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~the default element 0 is outside the min/max~');

rejects('a list default whose elements break its pattern', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', pattern: '/^\d+$/u')] StringList $v = new StringList(['bad'])): CommandResult
	{
		return CommandResult::nothing();
	}
}, "~the default element 'bad' does not match its own pattern~");

// A flag can only ever be SET. One that starts true has no value left to take, so the option is
// decoration and the name says the opposite of what the script does.
rejects('a flag that is already true', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] bool $v = true): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~can never take another value~');

rejects('allowEmpty where empty is not a value', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', allowEmpty: true)] int $v = 1): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~allowEmpty applies to a string~');

rejects('a separator on something that is never split', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', separator: ';')] int $v = 1): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~separator applies to a list~');

// minCount would mean both "how many arguments" and "how many elements in each", and the coercer
// duly checked it twice, against two different numbers.
rejects('a variadic of lists', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Arg('u', minCount: 2)] StringList ...$v): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~a variadic of lists gives minCount two meanings~');

rejects('a required list with no empty-input policy', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] StringList $v): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~required ValueList needs minCount: 0.*or minCount: 1~');

rejects('the helpPages overview key as a command', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function overview(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~overview.*reserved by helpPages~');

rejects('DateTimeImmutable without an explicit timezone', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u')] ?DateTimeImmutable $v = null): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~DateTimeImmutable value needs an explicit timezone~');

// --- One declaration per symbol ----------------------------------------------------------------
//
// Both attributes used to be read and the first silently won, so the help described the loser.

rejects('a parameter that is both', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Arg('p')] #[Opt('o')] string $v = ''): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~carries #\[Arg\] and #\[Opt\]~');

// --- Commands the runner could never call ------------------------------------------------------

rejects('a private command', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('hidden')]
	private function commandSecret(): CommandResult
	{
		return CommandResult::nothing();
	}

	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~must be public~');

rejects('a static command', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public static function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~cannot be static~');

rejects('a command name nobody could type', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function command_foo(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~cannot become a command name~');

rejects('a command that returns something else', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(): string
	{
		return 'no';
	}
}, '~must return CommandResult, not string~');

rejects('a command that declares no return type', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA()
	{
		return CommandResult::nothing();
	}
}, '~must return CommandResult, not nothing declared~');

// --- Globals ------------------------------------------------------------------------------------

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

rejects('a readonly global', static fn() => new #[Cli('x')] class extends Commands {
	#[Opt('frozen')]
	public readonly bool $raw;

	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~cannot be readonly~');

rejects('onlyWhen on a command parameter', static fn() => new #[Cli('x')] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('u', onlyWhen: Mutates::class)] bool $v = false): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~onlyWhen applies to a global~');

rejects('onlyWhen naming something that is not an attribute', static fn() => new #[Cli('x')] class extends Commands {
	#[Opt('write', onlyWhen: stdClass::class)]
	public bool $confirm = false;

	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~is not an attribute class~');

// --- #[CatchAs] -----------------------------------------------------------------------------------
//
// A mapping is the one place a fault must not appear, and every one of these produced its fault
// from inside the catch block: `format: 'broken %s %s'` threw ArgumentCountError while reporting
// the exception it was supposed to be reporting.

rejects('a CatchAs that is not throwable', static fn() => new #[Cli('x')] #[CatchAs(stdClass::class, exitCode: 4)] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~is not a Throwable~');

rejects('an exit code the shell cannot carry', static fn() => new #[Cli('x')] #[CatchAs(RuntimeException::class, exitCode: 999)] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~exitCode 999 is outside 1-255~');

rejects('an exit code of success', static fn() => new #[Cli('x')] #[CatchAs(RuntimeException::class, exitCode: 0)] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~exitCode 0 is outside 1-255~');

rejects('a format sprintf could not fill', static fn() => new #[Cli('x')] #[CatchAs(RuntimeException::class, exitCode: 4, format: 'broken %s %s')] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~must carry exactly one %s~');

rejects('a format spanning lines', static fn() => new #[Cli('x')] #[CatchAs(RuntimeException::class, exitCode: 4, format: "broken\n%s")] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~spans several lines~');

rejects('a format spanning lines with carriage return', static fn() => new #[Cli('x')] #[CatchAs(RuntimeException::class, exitCode: 4, format: "broken\r%s")] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~spans several lines~');

rejects('a format spanning lines with a Unicode separator', static fn() => new #[Cli('x')] #[CatchAs(RuntimeException::class, exitCode: 4, format: "broken\u{2028}%s")] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~spans several lines~');

rejects('a format carrying a terminal escape', static fn() => new #[Cli('x')] #[CatchAs(RuntimeException::class, exitCode: 4, format: "broken \x1B[31m%s")] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~contains terminal control characters~');

rejects('a mapping the runner handles first', static fn() => new #[Cli('x')] #[CatchAs(UsageError::class, exitCode: 4)] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~which the runner handles itself~');

// A fault stays a fault: mapping Throwable or an Error turns a TypeError into a tidy refusal, and
// a broken deployment then reads as a considered no.
rejects('a mapping that covers faults', static fn() => new #[Cli('x')] #[CatchAs(Throwable::class, exitCode: 4)] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~covers faults rather than a considered no~');

rejects('a mapping of an engine error', static fn() => new #[Cli('x')] #[CatchAs(TypeError::class, exitCode: 4)] class extends Commands {
	#[Command('a')]
	public function commandA(): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~covers faults rather than a considered no~');

rejects('a mapping an earlier one already catches', static fn() => new #[Cli('x')]
	#[CatchAs(LogicException::class, exitCode: 4)]
	#[CatchAs(DomainException::class, exitCode: 5)]
	class extends Commands {
		#[Command('a')]
		public function commandA(): CommandResult
		{
			return CommandResult::nothing();
		}
	}, '~already catches it~');

// --- WhenEmpty::Run ------------------------------------------------------------------------------
//
// It promises that no arguments is a complete invocation. Two declarations make that untrue, and
// both would otherwise only surface the first time someone ran the script with nothing.

rejects('Run with nothing to run', static fn() => new #[Cli('x', onEmpty: WhenEmpty::Run)] class extends Commands {
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
}, '~needs #\[Cli\(single: true\)\]~');

rejects('Run that could only fail', static fn() => new #[Cli('x', single: true, onEmpty: WhenEmpty::Run)] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('w')] string $what): CommandResult
	{
		return CommandResult::nothing();
	}
}, '~--what must be given~');

// --- And the supported declarations are genuinely accepted --------------------------------------
//
// Without this the gate could pass by refusing everything, which is the failure mode a validator
// grows into one refusal at a time.

$ok = new #[Cli('x')] #[CatchAs(RuntimeException::class, exitCode: 4, format: 'refused: %s')] class extends Commands {
	#[Opt('A global.')]
	public bool $raw = false;

	#[Opt('A gated global.', onlyWhen: Mutates::class)]
	public bool $confirm = false;

	protected function dateTimeZone(): \DateTimeZone
	{
		return new \DateTimeZone('Europe/Prague');
	}

	// The marker has to be carried by something: a gate no command passes through offers the
	// option to nothing, and this fixture is what proves the accepted end is still accepted.
	#[Mutates]
	#[Command('a')]
	public function commandA(
		#[Opt('s')] string $s = '',
		#[Opt('i', min: 1, max: 10)] int $i = 1,
		#[Opt('f', min: 0.5)] float $f = 1.5,
		#[Opt('b')] bool $b = false,
		#[Opt('e')] ?Colour $e = null,
		#[Opt('l')] ?StringList $l = null,
		#[Opt('n', minCount: 1, maxCount: 3)] ?NoteTypeList $n = null,
		#[Opt('d')] ?DateTimeImmutable $d = null,
		#[Opt('p', pattern: '/^\d{4}$/u')] string $p = '2026',
		#[Opt('y', pattern: '/^\d{4}$/u')] int $y = 2026,
		#[Opt('ids', min: 1, minCount: 1)] IntList $ids = new IntList([1, 2]),
		#[Arg('rest', minCount: 1)] string ...$rest,
	): CommandResult {
		return CommandResult::nothing();
	}
};
Assert::same(['a'], (new Introspector())->set($ok)->names());

// A variadic that insists is not optional, and the synopsis must not bracket it as if it were.
$spec = (new Introspector())->set($ok)->get('a')->variadic();
Assert::true($spec?->mustBeGiven());

// A single set whose parameters are all optional is exactly what WhenEmpty::Run is for.
$runnable = new #[Cli('x', single: true, onEmpty: WhenEmpty::Run)] class extends Commands {
	#[Command('a')]
	public function commandA(#[Opt('v')] bool $verbose = false): CommandResult
	{
		return CommandResult::nothing();
	}
};
Assert::same(['a'], (new Introspector())->set($runnable)->names());
