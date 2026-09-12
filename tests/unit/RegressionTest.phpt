<?php

declare(strict_types=1);

/**
 * One case per defect found in review. Each was reproduced against the code before being fixed, so
 * every assertion here is known to catch its defect rather than merely to pass.
 *
 * One entry (30) is the exception: its defect was in the documentation, and the assertions pin the
 * behaviour the corrected text now rests on so a later change cannot make that text wrong silently.
 */

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\Arg;
use Espego\CliRouter\CatchAs;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\DeclarationError;
use Espego\CliRouter\HelpRenderer;
use Espego\CliRouter\IntList;
use Espego\CliRouter\Introspector;
use Espego\CliRouter\Opt;
use Espego\CliRouter\StreamOutput;
use Espego\CliRouter\StringList;
use Espego\CliRouter\Tests\DemoSet;
use Espego\CliRouter\Tests\FileSet;
use Espego\CliRouter\Tests\Mutates;
use Espego\CliRouter\Tests\NoteTypeList;
use Espego\CliRouter\UsageError;
use Espego\CliRouter\WhenEmpty;
use Espego\CliRouter\Tests\EdgeSet;
use Tester\Assert;

/** A stream that accepts nothing, so a failed write can be asserted without a special device. */
final class RefusingStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_close(): void
    {
    }
}

/** A stream that accepts one byte at a time — what fwrite() is allowed to do and rarely does. */
final class PartialStream
{
    public static int $written = 0;

    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        self::$written = 0;

        return true;
    }

    public function stream_write(string $data): int
    {
        self::$written++;

        return 1;
    }

    public function stream_close(): void
    {
    }
}

stream_wrapper_register('refusing', RefusingStream::class);
stream_wrapper_register('partial', PartialStream::class);

/** @return array{int, string, string} */
function edge(array $argv): array
{
    return edgeOn(new EdgeSet(), $argv);
}

/** The same, on a set instance the caller keeps — so a second call can see what the first left. */
function edgeOn(EdgeSet $set, array $argv): array
{
    $out = new BufferedOutput();
    $code = $set->handle(['edge.php', ...$argv], $out);

    return [$code, $out->out, $out->err];
}

// --- 1. A value json_encode cannot represent ------------------------------------------------
//
// It used to exit 0 having written a lone newline to stdout and nothing to stderr. stdout here is
// contractually machine-readable, so a silent empty success is the one failure a `| jq` pipeline
// cannot notice. It is a fault in the command, not bad usage, so it escapes uncaught — and it must
// escape before anything has been written.

$out = new BufferedOutput();
Assert::exception(
    static fn() => (new EdgeSet())->handle(['edge.php', 'unencodable'], $out),
    JsonException::class,
);
Assert::same('', $out->out, 'a failed encode must not leave a partial line on stdout');
Assert::same('', $out->err);

// --- 2. Int-backed enums ---------------------------------------------------------------------
//
// tryFrom() on an int-backed enum rejects a string outright under strict_types, so passing the raw
// argument threw a TypeError out of the coercer — the layer whose whole job is to stop engine
// errors reaching the user.

Assert::same(1, json_decode(edge(['level', '--level=1'])[1], true));
Assert::same([1, 2], json_decode(edge(['levels', '--levels=1,2'])[1], true));

// An invalid case still reports as usage, with the list generated from ::cases().
[$code, , $err] = edge(['level', '--level=9']);
Assert::same(1, $code);
Assert::same("error: invalid --level '9'. Allowed: 1, 2.\n", $err);

// A non-numeric value is refused too, rather than becoming 0.
[, , $err] = edge(['level', '--level=loud']);
Assert::same("error: invalid --level 'loud'. Allowed: 1, 2.\n", $err);

// --- 3. Declarations that cannot work --------------------------------------------------------

// `help` is answered by the runner, so a command of that name could never execute.
$reservedCommand = new #[Cli('x')] class extends Commands {
    #[Command('shadowed')]
    public function commandHelp(): CommandResult
    {
        return CommandResult::nothing();
    }
};
Assert::exception(
    static fn() => (new Introspector())->set($reservedCommand),
    LogicException::class,
    '~reserved~',
);

// Same for an option: --help is intercepted before validation.
$reservedOption = new #[Cli('x')] class extends Commands {
    #[Command('a')]
    public function commandA(
        #[Opt('nope')]
        bool $help = false,
    ): CommandResult {
        return CommandResult::nothing();
    }
};
Assert::exception(
    static fn() => (new Introspector())->set($reservedOption),
    LogicException::class,
    '~reserved~',
);

// Two methods normalising to one name: one silently replaced the other in the command map.
$collision = new #[Cli('x')] class extends Commands {
    #[Command('first')]
    public function commandFoo(): CommandResult
    {
        return CommandResult::nothing();
    }

    #[Command('second')]
    public function foo(): CommandResult
    {
        return CommandResult::nothing();
    }
};
Assert::exception(
    static fn() => (new Introspector())->set($collision),
    LogicException::class,
    '~both~',
);

// --- 4. ext-mbstring is a real runtime requirement -------------------------------------------
//
// The help renderer counts characters, not bytes. A missing extension should fail at install
// rather than at the first --help.

$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
Assert::true(isset($manifest['require']['ext-mbstring']));

// --- 5. Duplicate options ---------------------------------------------------------------------
//
// Keeping the last occurrence meant a malformed flag could be rescued by a later well-formed one:
// `--confirm=false --confirm` yielded true and the write proceeded.

[$code, , $err] = edge(['save', '--what=x', '--confirm=false', '--confirm']);
Assert::same(1, $code);
Assert::same("error: --confirm was given more than once\n", $err);

// A single malformed one still reports as such.
[, , $err] = edge(['save', '--what=x', '--confirm=false']);
Assert::same("error: --confirm is a flag and takes no value\n", $err);

// Every repeated name is named, not just the first.
[, , $err] = edge(['save', '--what=a', '--what=b', '--confirm', '--confirm']);
Assert::same("error: --what, --confirm were given more than once\n", $err);

// --- 6. Cardinality is minCount/maxCount ---------------------------------------------------------
//
// min/max used to do double duty as value bounds and as collection size, so a list could not have
// both. Splitting them left the count checks with no coverage at all.

Assert::same(['a', 'b'], json_decode(edge(['tags', '--tags=a,b'])[1], true));

[$code, , $err] = edge(['tags', '--tags=a']);
Assert::same(1, $code);
Assert::same("error: at least 2 <tag> are required\n", $err);

[, , $err] = edge(['tags', '--tags=a,b,c,d']);
Assert::same("error: at most 3 <tag> are accepted\n", $err);

// --- 7. `--` ends option parsing ----------------------------------------------------------------
//
// Without it a file-oriented CLI cannot accept a path beginning with `--` by any means.

$result = json_decode(edge(['files', '--', '--strange.pdf'])[1], true);
Assert::same(['--strange.pdf'], $result['paths']);
Assert::false($result['json']);

// Options before `--` still parse; everything after it is positional whatever it looks like.
$result = json_decode(edge(['files', '--json', '--', '--a', '-b', 'c'])[1], true);
Assert::true($result['json']);
Assert::same(['--a', '-b', 'c'], $result['paths']);

// A lone `--` with nothing after it introduces no positional.
Assert::same([], json_decode(edge(['files', '--'])[1], true)['paths']);

// --- 8. A write that does not complete is not a success -------------------------------------------
//
// fwrite() may write fewer bytes than it was given and returns false on failure; ignoring either
// means truncated JSON that still exits 0. The behaviour was fixed in the same round as the rest
// and was the one fix that never got a case of its own.

$refusing = fopen('refusing://void', 'w');
assert(is_resource($refusing));
Assert::exception(
    static fn() => (new StreamOutput($refusing))->out('anything'),
    RuntimeException::class,
    'could not write to stdout',
);

$partial = fopen('partial://void', 'w');
assert(is_resource($partial));
$counted = new StreamOutput($partial);
$counted->out(str_repeat('x', 10));
Assert::same(10, PartialStream::$written, 'a short write must be resumed, not dropped');

// --- 9. Invocation context must not outlive the call ---------------------------------------------
//
// It used to persist, so a direct call after a completed handle() reported the previous command's
// name — a lie that only shows up in a message a human then reads.

$set = new EdgeSet();
$set->handle(['edge.php', 'whoami'], new BufferedOutput());
Assert::exception(
    static fn() => $set->commandWhoami(),
    LogicException::class,
    'no command is running',
);

// --- 10. A malformed help request is not a help request -------------------------------------------
//
// Any occurrence of --help was authoritative, so `--help=false` — which reads as "do not show
// help" to everyone who types it — printed help and exited 0, and a typo'd command name vanished
// behind the help it triggered. Both are the `--confirm=false` defect in another costume: an
// answer nobody asked for, reported as success.

[$code, $out, $err] = edge(['--help=false']);
Assert::same(1, $code);
Assert::same('', $out);
Assert::same("error: --help is a flag and takes no value\n", $err);

[$code, $out, $err] = edge(['tugs', '--help']);
Assert::same(1, $code);
Assert::same('', $out);
Assert::contains("unknown command 'tugs'", $err);

// The extra word was silently dropped, so `help save --raw` looked answered.
[$code, , $err] = edge(['help', 'save', 'extra']);
Assert::same(1, $code);
Assert::same("error: help describes one command. Run: help save\n", $err);

// A real help request still works, and still resolves before the options it describes.
[$code, $out, $err] = edge(['save', '--help']);
Assert::same(0, $code);
Assert::same('', $err);
Assert::contains('--what', $out);

// --- 11. Output must not be answerable outside a command ------------------------------------------
//
// The set stored the Output before routing, so help, an empty invocation and a usage error all
// left one behind. A direct call afterwards then wrote into a buffer belonging to a call that had
// already returned — visible to the caller of that earlier handle(), which is nobody's intent.

$set = new EdgeSet();
$stale = new BufferedOutput();
$set->handle(['edge.php', '--help'], $stale);

Assert::exception(
    static fn() => $set->commandSpeak(),
    LogicException::class,
    'no command is running',
);
Assert::same('', $stale->err);
Assert::notContains('spoken', $stale->out);

// Inside a dispatch it is answerable, and it writes where that dispatch was told to.
$live = new BufferedOutput();
$set->handle(['edge.php', 'speak'], $live);
Assert::contains('spoken', $live->out);

// --- 12. trim: false reaches the elements of a list -----------------------------------------------
//
// The raw value honoured it and every element was trimmed anyway, so the setting was inert for the
// one type where a caller would notice.

Assert::same([' a ', ' b '], json_decode(edge(['loose', '--loose= a , b '])[1], true));

// Stray separators are still dropped: `--loose=a,,b` is two elements whatever the trimming says.
Assert::same(['a', 'b'], json_decode(edge(['loose', '--loose=a,,b'])[1], true));

// --- 13. A variadic that insists is not optional --------------------------------------------------
//
// The synopsis bracketed anything that was not isRequired(), and a variadic never is — so a
// minCount: 1 variadic was advertised as `[<file>...]`, which says the opposite of what it means.

$insists = new #[Cli('x')] class extends Commands {
    #[Command('a')]
    public function commandA(
        #[Arg('A file.', placeholder: 'file', minCount: 1)]
        string ...$file,
    ): CommandResult {
        return CommandResult::nothing();
    }
};
$out = new BufferedOutput();
$insists->handle(['x.php', 'help', 'a'], $out);
Assert::contains('x.php a <file>...', $out->out);
Assert::notContains('[<file>...]', $out->out);

// --- 14. An exit code the shell cannot carry -------------------------------------------------------
//
// The shell reads one byte of it. 999 arrived as 231 and 256 as SUCCESS, and fail('bad', 0) printed
// a diagnostic and then reported that everything had gone well — the worst of the three, because a
// wrapper script believes it.

Assert::exception(
    static fn() => new UsageError('bad', 0),
    DeclarationError::class,
    'a UsageError exit code is 1-255, not 0.',
);
Assert::exception(
    static fn() => CommandResult::nothing(999),
    DeclarationError::class,
    'a command result exit code is 0-255, not 999.',
);
Assert::exception(
    static fn() => CommandResult::nothing(256),
    DeclarationError::class,
    'a command result exit code is 0-255, not 256.',
);

// The whole range a shell can carry is still available, ends included.
Assert::same(0, CommandResult::nothing(0)->exitCode);
Assert::same(255, CommandResult::nothing(255)->exitCode);
Assert::same(1, (new UsageError('bad'))->exitCode);

// --- 15. argv is bytes, and everything above the parser assumes text -------------------------------
//
// safe() asked preg to read the message as UTF-8 and got null back for malformed input, which the
// cast turned into the empty string: the diagnostic about the bad bytes was erased by them, and
// `error:` was all that was left. With a consumer /u pattern it was worse — an InternalError raised
// during coercion, in a try that caught only UsageError, so it escaped uncaught.

$invalid = "sm\xC3\x28ll";

[$code, $out, $err] = edge([$invalid]);
Assert::same(1, $code);
Assert::same('', $out);
Assert::same("error: argument 1 is not valid UTF-8\n", $err);

[$code, , $err] = edge(['save', '--what=' . $invalid]);
Assert::same(1, $code);
Assert::same("error: argument 2 is not valid UTF-8\n", $err);

// Valid UTF-8 that is not ASCII is text like any other, and must not be caught by the same net.
Assert::same('příliš', json_decode(edge(['save', '--what=příliš', '--confirm'])[1], true)['what']);

// --- 16. `help` takes no options -------------------------------------------------------------------
//
// The positional branch returned before it had looked at the options at all, so a valued --help and
// an outright unknown option both rendered help and exited 0.

foreach ([['help', 'save', '--help=false'], ['help', '--bogus']] as $argv) {
    [$code, $out, $err] = edge($argv);
    Assert::same(1, $code, implode(' ', $argv));
    Assert::same('', $out, implode(' ', $argv));
    Assert::same("error: help takes no options\n", $err);
}

// `help <command>` itself still answers.
[$code, $out] = edge(['help', 'save']);
Assert::same(0, $code);
Assert::contains('--what', $out);

// --- 17. A global must not outlive the call --------------------------------------------------------
//
// Only the globals applying to the command were assigned, and none were ever put back. On one
// instance, --confirm on a marked command was still true for the next, unmarked one — and repeated
// in-process handle() calls are something this package supports on purpose (see 9).

$gated = new #[Cli('x')] class extends Commands {
    #[Opt('Actually write.', onlyWhen: Mutates::class)]
    public bool $confirm = false;

    #[Mutates]
    #[Command('Write.')]
    public function commandWrite(): CommandResult
    {
        return CommandResult::json($this->confirm);
    }

    #[Command('Read.')]
    public function commandRead(): CommandResult
    {
        return CommandResult::json($this->confirm);
    }
};

$out = new BufferedOutput();
$gated->handle(['x.php', 'write', '--confirm'], $out);
Assert::true(json_decode($out->out, true));

// The next command does not take --confirm at all, so it must see what the property declares.
$out = new BufferedOutput();
$gated->handle(['x.php', 'read'], $out);
Assert::false(json_decode($out->out, true), 'a gated global must not carry into a command that cannot take it');

// And the instance is left holding what it declared, not what the last call set.
Assert::false($gated->confirm);

// --- 18. A command that takes no arguments can be run ----------------------------------------------
//
// Every empty invocation went to the help/error branch, so a `status`, `sync` or `flush` script
// could not exist without a dummy flag to make argv non-empty.

$status = new #[Cli('Report status.', single: true, onEmpty: WhenEmpty::Run)] class extends Commands {
    #[Command('Say whether things are well.')]
    public function commandStatus(
        #[Opt('Say more.')]
        bool $verbose = false,
    ): CommandResult {
        return CommandResult::json(['ok' => true, 'verbose' => $verbose]);
    }
};

$out = new BufferedOutput();
Assert::same(0, $status->handle(['status.php'], $out));
Assert::same(['ok' => true, 'verbose' => false], json_decode($out->out, true));

// Options still reach it, and --help still wins over running.
$out = new BufferedOutput();
$status->handle(['status.php', '--verbose'], $out);
Assert::true(json_decode($out->out, true)['verbose']);

$out = new BufferedOutput();
Assert::same(0, $status->handle(['status.php', '--help'], $out));
Assert::contains('Say whether things are well.', $out->out);

// --- 19. The help states the constraints the declaration sets --------------------------------------
//
// A bound that only appears when it is violated is a bound nobody can plan around; and a single set
// never reached command(), so the summary its one command must declare was never printed at all.

$out = new BufferedOutput();
(new EdgeSet())->handle(['edge.php', 'help', 'tags'], $out);
Assert::contains('Between 2 and 3 values.', $out->out);

$out = new BufferedOutput();
(new Espego\CliRouter\Tests\FileSet())->handle(['files.php', '--help'], $out);
Assert::contains('Audit one or more files.', $out->out);

// --- 20. A default is held to what a typed value meets (review finding 1) --------------------------
//
// The default never passes through the coercer, so introspection is the only place it can be asked
// the questions an argument is asked. Two routes round that survived the last round: a list default
// was asked what its elements ARE rather than what the list DECLARES, and a pattern was matched
// against string defaults only — so `--n=1` was refused while `int $n = 1` sailed past it.

/** @param callable(): object $make */
function refuses(callable $make, string $expected): void
{
    Assert::exception(static fn() => (new Introspector())->set($make()), DeclarationError::class, $expected);
}

// Reported. Worse than "the command gets a string": IntList::first() is typed, so the string left
// again as an uncaught TypeError raised by the consumer's own accessor.
refuses(static fn() => new #[Cli('x')] class extends Commands {
    #[Command('a')]
    public function commandA(#[Opt('ids')] IntList $ids = new IntList(['wrong'])): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~the default holds string where the list declares int~');

// The other direction through the same predicate, and the enum branch of it — neither element is
// the type its list promises, and neither used to be asked.
refuses(static fn() => new #[Cli('x')] class extends Commands {
    #[Command('a')]
    public function commandA(#[Opt('t')] StringList $t = new StringList([1, 2])): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~the default holds int where the list declares string~');

refuses(static fn() => new #[Cli('x')] class extends Commands {
    #[Command('a')]
    public function commandA(#[Opt('c')] NoteTypeList $c = new NoteTypeList(['red'])): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~the default holds string where the list declares~');

// Reported: the pattern is matched against the raw argument before it becomes a number, so an
// explicit --n=1 was refused while the declared default 1 was not looked at.
refuses(static fn() => new #[Cli('x')] class extends Commands {
    #[Command('a')]
    public function commandA(#[Opt('n', pattern: '/^\d{2}$/u')] int $n = 1): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~the default 1 does not match its own pattern~');

// The same value arriving as a float, and as an element of a list — the coercer patterns both.
refuses(static fn() => new #[Cli('x')] class extends Commands {
    #[Command('a')]
    public function commandA(#[Opt('f', pattern: '/^\d{2}$/u')] float $f = 1.5): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~the default 1.5 does not match its own pattern~');

refuses(static fn() => new #[Cli('x')] class extends Commands {
    #[Command('a')]
    public function commandA(#[Opt('ids', pattern: '/^\d{2}$/u')] IntList $ids = new IntList([1])): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~the default element 1 does not match its own pattern~');

// The accepted end, through the runtime rather than the gate: a correct list default reaches the
// command as the elements its signature promises, and a pattern still admits what it was written
// for — from the declaration and from argv alike.
$defaults = new #[Cli('x', single: true)] class extends Commands {
    #[Command('Report the defaults it was handed.')]
    public function commandA(
        #[Opt('ids', min: 1, minCount: 1)]
        IntList $ids = new IntList([1, 2]),
        #[Opt('year', pattern: '/^\d{4}$/u')]
        int $year = 2026,
    ): CommandResult {
        return CommandResult::json(['ids' => $ids->all(), 'year' => $year]);
    }
};

$out = new BufferedOutput();
Assert::same(0, $defaults->handle(['x.php', '--year=2026'], $out));
Assert::same(['ids' => [1, 2], 'year' => 2026], json_decode($out->out, true));

// --- 21. A DeclarationError is nobody's considered no (review finding 2) ---------------------------
//
// #[CatchAs] exists so an upstream refusal can become an exit code. A DeclarationError is the
// opposite: the consumer's own set is wrong, and it has to reach the first run as a fatal. Mapping
// LogicException — a reasonable thing to map — turned CommandResult::nothing(999) into `error: …`
// and exit 4: a broken declaration wearing the tidy refusal that #[CatchAs] exists not to hand out.

$mapped = new #[Cli('x')] #[CatchAs(LogicException::class, exitCode: 4)] class extends Commands {
    #[Command('An exit code the shell cannot carry.')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing(999);
    }

    #[Command('A usage exit code that means success.')]
    public function commandB(): CommandResult
    {
        $this->fail('nope', 0);
    }

    #[Command('A considered no from somewhere else.')]
    public function commandC(): CommandResult
    {
        throw new DomainException('upstream said no');
    }
};

Assert::exception(
    static fn() => $mapped->handle(['x.php', 'a'], new BufferedOutput()),
    DeclarationError::class,
    'a command result exit code is 0-255, not 999.',
);

// The same swallow reached by another route: UsageError's own constructor refuses exit code 0.
Assert::exception(
    static fn() => $mapped->handle(['x.php', 'b'], new BufferedOutput()),
    DeclarationError::class,
    'a UsageError exit code is 1-255, not 0.',
);

// The accepted end: a genuine exception still maps, which is the whole point of #[CatchAs].
$out = new BufferedOutput();
Assert::same(4, $mapped->handle(['x.php', 'c'], $out));
Assert::same("error: upstream said no\n", $out->err);

// And naming it outright is refused where every other self-handled exception already was.
refuses(static fn() => new #[Cli('x')] #[CatchAs(DeclarationError::class, exitCode: 4)] class extends Commands {
    #[Command('a')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~which the runner handles itself~');

// --- 22. A help request validates option names (review finding 3) ----------------------------------
//
// The positional branch was fixed in 16; the option branch still answered `save --help --bogus` with
// the help and exit 0. Skipping required-ness and coercion is what makes --help useful on a command
// whose options are mandatory — skipping the NAMES is how an unknown option becomes a silent no-op.

$helped = new #[Cli('x')] class extends Commands {
    #[Opt('Actually write.')]
    public bool $confirm = false;

    #[Command('Save something.')]
    public function commandSave(#[Opt('What.')] string $what = 'x'): CommandResult
    {
        return CommandResult::nothing();
    }
};

foreach ([['save', '--help', '--bogus'], ['--help', '--bogus']] as $argv) {
    $out = new BufferedOutput();
    Assert::same(1, $helped->handle(['x.php', ...$argv], $out), implode(' ', $argv));
    Assert::same('', $out->out, implode(' ', $argv));
    Assert::contains('unknown option --bogus', $out->err, implode(' ', $argv));
}

// A single set answers --help without naming a command, so it reaches the check by a third route.
$only = new #[Cli('x', single: true)] class extends Commands {
    #[Command('The only one.')]
    public function commandOnly(#[Opt('Loudly.')] bool $verbose = false): CommandResult
    {
        return CommandResult::nothing();
    }
};

$out = new BufferedOutput();
Assert::same(1, $only->handle(['x.php', '--help', '--bogus'], $out));
Assert::contains('unknown option --bogus', $out->err);

// The accepted end: help still answers, still before the options it describes, and an option that
// does exist is not in the way — including a global, which is what --help is usually typed beside.
foreach ([['save', '--help'], ['save', '--help', '--confirm'], ['--help']] as $argv) {
    $out = new BufferedOutput();
    Assert::same(0, $helped->handle(['x.php', ...$argv], $out), implode(' ', $argv));
    Assert::same('', $out->err, implode(' ', $argv));
    Assert::contains('Save something.', $out->out, implode(' ', $argv));
}

$out = new BufferedOutput();
Assert::same(0, $only->handle(['x.php', '--help', '--verbose'], $out));
Assert::contains('The only one.', $out->out);

// --- 23. Metadata that cannot be reached (review finding 4) ----------------------------------------
//
// Both read as configured and neither can do what it says: a gated global whose marker no command
// carries is offered to nothing, and a heading declared twice lists its commands twice.

refuses(static fn() => new #[Cli('x')] class extends Commands {
    #[Opt('Actually write.', onlyWhen: Mutates::class)]
    public bool $confirm = false;

    #[Command('a')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~no #\[Command\] of this set carries~');

refuses(static fn() => new #[Cli('x', groups: ['G', 'G'])] class extends Commands {
    #[Command('a', group: 'G')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, "~declares 'G' twice~");

// The sibling the review did not name: a heading nothing is filed under renders nothing at all, so
// it is the same inert declaration with no output to give it away.
refuses(static fn() => new #[Cli('x', groups: ['Used', 'Unused'])] class extends Commands {
    #[Command('a', group: 'Used')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, "~declares 'Unused', which no~");

refuses(static fn() => new #[Cli('x', groups: [''])] class extends Commands {
    #[Command('a')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~empty heading~');

// The accepted end: the shipped demo declares two headings and fills both, and gates a global on a
// marker two of its commands carry. And "Other commands:" still catches a command that declares NO
// group in a set that declares some — which is what that branch was written for. This case used to
// reach it with a group name nobody declared; 27 refuses that, because it renders identically to
// the ungrouped command below and so was never a second route to anything.
Assert::contains('env', (new Introspector())->set(new DemoSet())->names());

$other = new #[Cli('x', groups: ['Used'])] class extends Commands {
    #[Command('a', group: 'Used')]
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

$out = new BufferedOutput();
Assert::same(0, $other->handle(['x.php', 'help'], $out));
Assert::contains('Other commands:', $out->out);

// --- 24. The overview accepts exactly what the overview prints (review finding 1) ------------------
//
// 22 taught the option branch to check option NAMES, and took every global as the set a named-less
// help request may use. But a marker-gated global applies to some commands and not others, so the
// overview help has always left it out — and the runner accepted it anyway. An option accepted where
// the help that answers it does not mention it is the same silent no-op 22 was about, one level up.

$gated = new #[Cli('x')] class extends Commands {
    #[Opt('Print the raw response.')]
    public bool $raw = false;

    #[Opt('Actually write.', onlyWhen: Mutates::class)]
    public bool $confirm = false;

    #[Mutates]
    #[Command('Write something.')]
    public function commandWrite(): CommandResult
    {
        return CommandResult::nothing();
    }

    #[Command('Read something.')]
    public function commandRead(): CommandResult
    {
        return CommandResult::nothing();
    }
};

// Reported: the overview takes no command, so a gated option cannot apply to whatever runs.
$out = new BufferedOutput();
Assert::same(1, $gated->handle(['x.php', '--help', '--confirm'], $out));
Assert::same('', $out->out);
Assert::contains('unknown option --confirm', $out->err);

// The three cells of the matrix that were already right, so the fix cannot have moved them: an
// unconditional global on the overview, and the same gated one on a command page either side of its
// marker.
foreach ([['--help', '--raw'], ['write', '--help', '--confirm']] as $argv) {
    $out = new BufferedOutput();
    Assert::same(0, $gated->handle(['x.php', ...$argv], $out), implode(' ', $argv));
    Assert::same('', $out->err, implode(' ', $argv));
}

$out = new BufferedOutput();
Assert::same(1, $gated->handle(['x.php', 'read', '--help', '--confirm'], $out));
Assert::contains("unknown option --confirm for 'read'", $out->err);

// And the property behind all four, which holds whichever side is wrong: on the overview, an option
// is accepted if and only if the overview's own help lists it.
$overview = new BufferedOutput();
$gated->handle(['x.php', '--help'], $overview);

foreach (['raw', 'confirm'] as $name) {
    $listed = str_contains($overview->out, "--{$name}");
    $out = new BufferedOutput();
    Assert::same(
        $listed ? 0 : 1,
        $gated->handle(['x.php', '--help', "--{$name}"], $out),
        "--{$name} is " . ($listed ? 'listed' : 'not listed') . ' in the overview, so that is what it must do',
    );
}

// --- 25. Metadata only the command list can render (review finding 4, continued) -------------------
//
// 23 refused a heading no command names. These are the rest of that class: a single set prints no
// command list at all, so everything only the list reads is inert on one — and a heading whose
// members are all hidden renders exactly as much as a heading nobody named.

refuses(static fn() => new #[Cli('x', single: true, groups: ['G'])] class extends Commands {
    #[Command('a', group: 'G')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~prints no command list, so groups would never be rendered~');

// The same inert declaration reached through #[Command] instead of #[Cli].
refuses(static fn() => new #[Cli('x', single: true)] class extends Commands {
    #[Command('a', group: 'G')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~no command list to file it into~');

refuses(static fn() => new #[Cli('x', single: true)] class extends Commands {
    #[Command('a', hidden: true)]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~no command list to leave it out of~');

// Round 3 accepted this one, on the grounds that un-hiding the command would restore the heading.
// That argument fits adding a command just as well, and the heading nobody names was refused in the
// same commit — so the two were inconsistent rather than one of them being right.
refuses(static fn() => new #[Cli('x', groups: ['Ghost', 'Real'])] class extends Commands {
    #[Command('a', group: 'Ghost', hidden: true)]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }

    #[Command('b', group: 'Real')]
    public function commandB(): CommandResult
    {
        return CommandResult::nothing();
    }
}, "~declares 'Ghost', which no listed~");

// The accepted end: one visible member is enough to fill a heading, whatever is hidden beside it.
$mixed = new #[Cli('x', groups: ['Mixed'])] class extends Commands {
    #[Command('Hidden but dispatchable.', group: 'Mixed', hidden: true)]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }

    #[Command('Listed.', group: 'Mixed')]
    public function commandB(): CommandResult
    {
        return CommandResult::nothing();
    }
};

$out = new BufferedOutput();
Assert::same(0, $mixed->handle(['x.php', 'help'], $out));
Assert::contains('Mixed:', $out->out);
Assert::notContains('Hidden but dispatchable.', $out->out);

// And the shipped sets are untouched by any of it: one single with no list metadata, one multi whose
// headings are named by visible commands.
Assert::same(['run'], (new Introspector())->set(new FileSet())->names());
Assert::contains('env', (new Introspector())->set(new DemoSet())->names());

// --- 26. The Makefile names no path only one machine has (review finding 3) ------------------------
//
// It had a machine-local default for an external dependency checker, which read as a pass when the
// checker was absent, then failed honestly, then was still a path that is wrong in every clone. The
// answer was that this package does not owe that target at all — two pinned runtime requirements are
// something to run a workspace tool AT, not a dependency to take on. Asserted like 4, on the file.

$makefile = (string) file_get_contents(__DIR__ . '/../../Makefile');
Assert::notContains('$(HOME)', $makefile);
Assert::notContains('safe-update', $makefile);

// The gate itself is unchanged — removing a target must not quietly remove a check from it.
Assert::contains('check: lint ecs test deps-audit', $makefile);

// --- 27. A group name is a closed set in BOTH directions (review finding 1) ------------------------
//
// 23 and 25 walked #[Cli(groups:)] and refused a heading nothing fills. Nothing ever walked the
// commands, so a name that is not a heading was simply dropped — and with no groups declared at all
// the first loop does not even run, which is the reported shape. Measured before the fix: such a
// command renders BYTE-IDENTICALLY to one declaring no group, which is what makes the name inert
// rather than merely misfiled, and is what reverses the "left accepted on purpose" call in 23.

refuses(static fn() => new #[Cli('x')] class extends Commands {
    #[Command('a', group: 'Typo')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~which declares none~');

// The same name, now against headings that do exist: this is the typo the rule is really for, so
// the message names what was declared.
refuses(static fn() => new #[Cli('x', groups: ['Used'])] class extends Commands {
    #[Command('a', group: 'Used')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }

    #[Command('b', group: 'Nope')]
    public function commandB(): CommandResult
    {
        return CommandResult::nothing();
    }
}, "~group 'Nope' is not one of #\[Cli\(groups:\)\] \(Used\)~");

// And by case alone, which is the typo that reads as correct. Identifiers are case-sensitive here
// as everywhere else.
refuses(static fn() => new #[Cli('x', groups: ['Read'])] class extends Commands {
    #[Command('a', group: 'Read')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }

    #[Command('b', group: 'read')]
    public function commandB(): CommandResult
    {
        return CommandResult::nothing();
    }
}, "~group 'read' is not one of~");

// A hidden command is held to the same names — it cannot fill a heading (25), but it can still name
// one that does not exist.
refuses(static fn() => new #[Cli('x', groups: ['Real'])] class extends Commands {
    #[Command('a', group: 'Real')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }

    #[Command('b', group: 'Ghost', hidden: true)]
    public function commandB(): CommandResult
    {
        return CommandResult::nothing();
    }
}, "~group 'Ghost' is not one of~");

// The accepted end is asserted where that branch is: the rewritten case above 24, which reaches
// "Other commands:" with a command that declares no group at all.

// --- 28. A number the renderer cannot honour, and prose that says nothing (review finding 2) -------
//
// Measured across the flip point before the fix: width 0, 1, 20 and 44 all rendered a longest line
// of 43, and 45 rendered 45 — because row() floors the text column at MIN_TEXT, so below
// OPTION_GUTTER + MIN_TEXT the declared width moves nothing. A margin that reads as enforced and is
// not is the same defect as a min/max the coercer never checks.

refuses(static fn() => new #[Cli('x', width: 0)] class extends Commands {
    #[Command('a')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~is under 45, the narrowest the help can be rendered at~');

// One below the floor, which is the boundary that matters — 0 is obvious, 44 is not.
refuses(static fn() => new #[Cli('x', width: 44)] class extends Commands {
    #[Command('a')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~#\[Cli\(width: 44\)\] is under 45~');

// The accepted end of that boundary: the floor itself is legal, and it is honoured — which is the
// whole reason it is the floor. Asserted the way CanonicalHelpTest asserts the default width.
//
// (The summaries here were once deliberately short, because a summary was printed verbatim and a
// long one overran the margin at any width. Section 29 fixed that; they stay short because this
// section is about the floor, not about wrapping.)
$narrow = new #[Cli('Narrow.', width: HelpRenderer::MIN_WIDTH)] class extends Commands {
    #[Command('Do a thing.', description: 'A description long enough that it has to wrap more than once at this width.')]
    public function commandA(
        #[Opt('An option whose description is long enough that it must wrap more than once here.')]
        string $thing = 'x',
    ): CommandResult {
        return CommandResult::nothing();
    }
};

$out = new BufferedOutput();
Assert::same(0, $narrow->handle(['x.php', 'help', 'a'], $out));
Assert::contains("\n", trim($out->out), 'the help must actually have wrapped something');
foreach (explode("\n", $out->out) as $line) {
    Assert::true(mb_strlen($line) <= HelpRenderer::MIN_WIDTH, 'line past the declared margin: ' . $line);
}

// The default has always been legal and stays so.
Assert::same(92, (new Cli('x'))->width);
Assert::contains('env', (new Introspector())->set(new DemoSet())->names());

// Same class, second shape: a message only one WhenEmpty ever prints. Runner::empty() reads it in
// the Error branch alone, so under Help or HelpFailed it is a sentence nothing can reach.
refuses(static fn() => new #[Cli('x', single: true, onEmpty: WhenEmpty::Help, emptyMessage: 'never printed')] class extends Commands {
    #[Command('a')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~printed only by WhenEmpty::Error, and onEmpty is WhenEmpty::Help~');

// Paired correctly it is exactly what FileSet declares, and that has to keep working.
Assert::same(['run'], (new Introspector())->set(new FileSet())->names());

// Third shape: prose declared as nothing. The summary is the line #[Cli] exists to demand — its
// absence already throws "A command set declares its own summary" — so declaring it blank is the
// same omission wearing an argument.
refuses(static fn() => new #[Cli('   ')] class extends Commands {
    #[Command('a')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~summary is blank~');

refuses(static fn() => new #[Cli('x')] class extends Commands {
    #[Command('')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
}, '~summary is blank~');

foreach ([
    'before' => new #[Cli('x', before: '')] class extends Commands {
        #[Command('a')]
        public function commandA(): CommandResult
        {
            return CommandResult::nothing();
        }
    },
    'description' => new #[Cli('x')] class extends Commands {
        #[Command('a', description: '')]
        public function commandA(): CommandResult
        {
            return CommandResult::nothing();
        }
    },
] as $what => $set) {
    Assert::exception(
        static fn() => (new Introspector())->set($set),
        DeclarationError::class,
        "~{$what} is declared but blank~",
    );
}

// The accepted end that this rule must NOT touch: #[Opt] and #[Arg] default their description to '',
// and a bare #[Opt] is the fallback meta for a parameter carrying no attribute at all — so a blank
// description there is ordinary, not a mistake.
$terse = new #[Cli('x')] class extends Commands {
    #[Command('a')]
    public function commandA(
        #[Opt]
        string $s = '',
        int $n = 1,
    ): CommandResult {
        return CommandResult::nothing();
    }
};
Assert::same(['a'], (new Introspector())->set($terse)->names());

// --- 29. Declared prose is laid out at the declared width -----------------------------------------
//
// Found while asserting 28's accepted end, not reported: `width` moved the columns and nothing else.
// Everything the renderer lays out in a column had always wrapped, but everything it printed as a
// standalone paragraph did not — except a description — so a long summary overran the margin at
// every width, the default 92 included. CanonicalHelpTest passed throughout because DemoSet's prose
// is short, which is exactly how a margin comes to mean "the columns only".

// A constant, not a variable: an attribute argument has to be a constant expression.
const WORDY = 'This prose is far longer than the declared margin and must therefore be wrapped by the renderer.';

$wordy = new #[Cli(WORDY, width: HelpRenderer::MIN_WIDTH, before: WORDY, after: WORDY)] class extends Commands {
    #[Command(WORDY, description: WORDY)]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
};

// The reported field, and the two siblings nobody named — before and after are prose printed the
// same way and were overrunning the same margin.
foreach ([['help'], ['help', 'a']] as $argv) {
    $out = new BufferedOutput();
    Assert::same(0, $wordy->handle(['x.php', ...$argv], $out), implode(' ', $argv));
    foreach (explode("\n", $out->out) as $line) {
        Assert::true(
            mb_strlen($line) <= HelpRenderer::MIN_WIDTH,
            implode(' ', $argv) . ' — line past the declared margin: ' . $line,
        );
    }
}

// A single set reaches its command's summary by a different branch, so it is checked on its own.
$onlyWordy = new #[Cli(WORDY, single: true, width: HelpRenderer::MIN_WIDTH)] class extends Commands {
    #[Command(WORDY)]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
};

$out = new BufferedOutput();
Assert::same(0, $onlyWordy->handle(['x.php', '--help'], $out));
foreach (explode("\n", $out->out) as $line) {
    Assert::true(mb_strlen($line) <= HelpRenderer::MIN_WIDTH, 'single set — line past the margin: ' . $line);
}

// The accepted end: prose that chose its own line breaks keeps them, so a footer laid out by hand is
// not reflowed into one paragraph.
$laidOut = new #[Cli('Short.', after: "One.\nTwo.\n\nFour.")] class extends Commands {
    #[Command('a')]
    public function commandA(): CommandResult
    {
        return CommandResult::nothing();
    }
};

$out = new BufferedOutput();
$laidOut->handle(['x.php', 'help'], $out);
Assert::contains("One.\nTwo.\n\nFour.", $out->out);

// And short prose is untouched, which tests/unit/CanonicalHelpTest.phpt asserts byte for byte
// against DemoHelp.expect — that file did not move when this landed.


// --- 30. Not given is not the same as given empty (review finding 2) -------------------------------
//
// The only entry here whose defect was in the documentation rather than in the code: the guide had
// `string $s = ''` meaning "given, and empty", which is backwards. Measured, the three declarations
// answer three different ways, and nothing asserted the distinction the guide now rests on — so a
// later change to the coercer could make the corrected text wrong again with every test still green.

// onEmpty: Run, so the no-argument invocation reaches the body instead of printing help.
$empties = new #[Cli('x', single: true, onEmpty: WhenEmpty::Run)] class extends Commands {
    #[Command('a')]
    public function commandA(
        #[Opt('plain')]
        string $plain = '',
        #[Opt('permissive', allowEmpty: true)]
        string $permissive = '',
        #[Opt('nullable', allowEmpty: true)]
        ?string $nullable = null,
    ): CommandResult {
        return CommandResult::json([
            'plain' => $plain,
            'permissive' => $permissive,
            'nullable' => $nullable === null ? 'NOT GIVEN' : $nullable,
        ]);
    }
};

// Without allowEmpty the empty value is refused, so '' can only ever have come from the default.
// That is the opposite of what the guide claimed: the empty string means NOT given.
$out = new BufferedOutput();
Assert::same(1, $empties->handle(['x.php', '--plain='], $out));
Assert::same("error: --plain is required and must have a value\n", $out->err);

// With allowEmpty on a non-nullable string the two collapse into one value: the command cannot
// tell `--permissive=` from an omitted option, which is the reviewer's half of the finding.
$out = new BufferedOutput();
Assert::same(0, $empties->handle(['x.php', '--permissive='], $out));
Assert::contains('"permissive": ""', $out->out);
$out = new BufferedOutput();
Assert::same(0, $empties->handle(['x.php'], $out));
Assert::contains('"permissive": ""', $out->out);

// Nullable plus allowEmpty is the one shape that keeps them apart, and is what the guide now names.
$out = new BufferedOutput();
Assert::same(0, $empties->handle(['x.php', '--nullable='], $out));
Assert::contains('"nullable": ""', $out->out);
$out = new BufferedOutput();
Assert::same(0, $empties->handle(['x.php'], $out));
Assert::contains('"nullable": "NOT GIVEN"', $out->out);
