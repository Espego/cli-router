<?php

declare(strict_types=1);

/**
 * One case per defect found in review. Each was reproduced against the code before being fixed, so
 * every assertion here is known to catch its defect rather than merely to pass.
 */

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\Arg;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\Introspector;
use Espego\CliRouter\Opt;
use Espego\CliRouter\StreamOutput;
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
    $out = new BufferedOutput();
    $code = (new EdgeSet())->handle(['edge.php', ...$argv], $out);

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
