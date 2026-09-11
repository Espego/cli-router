<?php

declare(strict_types=1);

/**
 * One case per defect found in review. Each was reproduced against the code before being fixed, so
 * every assertion here is known to catch its defect rather than merely to pass.
 */

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\Introspector;
use Espego\CliRouter\Opt;
use Espego\CliRouter\Tests\EdgeSet;
use Tester\Assert;

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
