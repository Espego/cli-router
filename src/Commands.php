<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use LogicException;

/**
 * Extend this, declare commands as methods, and the entry script is one statement:
 *
 *     (new App\Cli\Pdf\CommandSet)->run();
 *
 * There is deliberately NO constructor here — not even an empty one. A subclass declaring its own
 * never has to call parent::__construct(), and a subclass declaring none stays instantiable with
 * zero arguments. Dependencies are the subclass's business: list them, typed, one per parameter,
 * so the constructor answers "what does this script need".
 */
abstract class Commands
{
    /**
     * Nullable with a null default rather than uninitialised: an uninitialised typed property
     * would make `new CommandSet` legal but any later read an Error.
     */
    private ?Invocation $invocation = null;

    private ?Output $output = null;

    /**
     * The whole entry script: guard the SAPI, read argv, dispatch, render, exit.
     *
     * final because the contract is that it never returns and exits with the code handle() computed.
     *
     * @param list<string>|null $argv Defaults to the real argv, script name included.
     */
    final public function run(?array $argv = null): never
    {
        if (PHP_SAPI !== 'cli') {
            fwrite(STDERR, static::class . " is a CLI tool.\n");
            exit(1);
        }

        exit($this->handle($argv ?? self::argv()));
    }

    /**
     * Everything run() does except exiting. This is what a test calls.
     *
     * @param list<string> $argv WITH the script name at [0].
     */
    final public function handle(array $argv, ?Output $output = null): int
    {
        $this->output = $output ?? new StreamOutput();

        return (new Runner($this))->handle($argv, $this->output);
    }

    /**
     * The real argv, filtered to strings so a malformed $_SERVER cannot reach the parser.
     *
     * @return list<string>
     */
    private static function argv(): array
    {
        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) ? array_values(array_filter($argv, 'is_string')) : [];
    }

    /**
     * The one imperative hook: layers that run after validation and before the command body.
     *
     * Empty for most sets. Override it for what is genuinely per-invocation rather than
     * per-command — announcing which environment the process is wired to, asserting the caller
     * meant this one — and which must not fire for a typo that never reached a command.
     *
     * @return list<Middleware>
     */
    protected function middleware(): array
    {
        return [];
    }

    /** Which command is running. Lets a message name the command without repeating it as a literal. */
    final protected function invocation(): Invocation
    {
        return $this->invocation ?? throw new LogicException('no command is running');
    }

    /** For a body that reports progress as it goes rather than only at the end. */
    final protected function output(): Output
    {
        return $this->output ?? throw new LogicException('no command is running');
    }

    /**
     * Bad usage, reported from anywhere — including several frames down inside a helper.
     *
     * Throws rather than exits, so the message is assertable in a test and a command body never
     * has to end the process itself.
     */
    final protected function fail(string $message, int $exitCode = 1): never
    {
        throw new UsageError($message, $exitCode);
    }

    /**
     * Finish early with this result, from anywhere.
     *
     * Rare on purpose: a command that can return its result should return it. This is for when the
     * decision is made deep in a helper and threading the value back would obscure it.
     */
    final protected function stop(CommandResult $result): never
    {
        throw new Stop($result);
    }

    /**
     * @internal Called by Runner once the invocation is fully validated.
     */
    final public function bindInvocation(Invocation $invocation, Output $output): void
    {
        $this->invocation = $invocation;
        $this->output = $output;
    }

    /**
     * @internal Called by Runner.
     * @return list<Middleware>
     */
    final public function middlewareStack(): array
    {
        return $this->middleware();
    }
}
