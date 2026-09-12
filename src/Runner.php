<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use Throwable;

/**
 * Parse, validate, dispatch, render, and report an exit code.
 *
 * The step order is the design. Help, an unknown command, an unknown option and every type,
 * pattern or range failure all resolve BEFORE a middleware runs and before a command body exists —
 * so a mistyped flag costs an error message and nothing else, whatever the command would have done.
 *
 * @internal Not part of the public surface; may change in any release.
 */
final class Runner
{
    public const EXIT_INTERNAL = 70;

    public function __construct(
        private readonly Commands $set,
        private readonly Introspector $introspector = new Introspector(),
        private readonly Coercer $coercer = new Coercer(),
        private readonly HelpRenderer $help = new HelpRenderer(),
        private readonly int $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ) {
    }

    /** @param list<string> $argv WITH the script name at [0]. */
    public function handle(array $argv, Output $output): int
    {
        $set = $this->introspector->set($this->set);

        // The program name reaches the help text, so it is sanitised like anything else the process
        // did not author — and computed inside the try, where a diagnostic can still be reported.
        try {
            $program = 'php ' . Diagnostic::line($argv[0] ?? 'script.php');

            ['args' => $args, 'opts' => $opts, 'duplicates' => $duplicates] =
                (new ArgumentParser())->parse(array_slice($argv, 1));

            // Keeping the last occurrence let a malformed flag be rescued by a well-formed one:
            // `--confirm=false --confirm` read as true and the write went ahead. Multi-value
            // options have a separator, so a repeat is a mistake either way.
            if ($duplicates !== []) {
                throw new UsageError(sprintf(
                    '%s %s given more than once',
                    implode(', ', array_map(static fn (string $o): string => '--' . $o, $duplicates)),
                    count($duplicates) === 1 ? 'was' : 'were',
                ));
            }

            $topic = $this->helpTopic($set, $args, $opts);
            if ($topic !== false) {
                // A help request skips required-ness and coercion — that is what lets --help answer
                // for a command whose options are mandatory — but not the option NAMES. Without
                // this, `save --help --bogus` printed the help and exited 0: the malformed-help
                // defect in its last costume, an answer to a question nobody asked, called success.
                $this->assertKnownOptions($set, $this->helpSubject($set, $topic), $opts, $program);

                $output->out($this->help->render($set, $topic, $program));

                return 0;
            }

            if ($args === [] && $opts === [] && $set->meta->onEmpty !== WhenEmpty::Run) {
                return $this->empty($set, $output, $program);
            }

            $command = $this->resolve($set, $args, $program);
            $this->assertKnownOptions($set, $command, $opts, $program);

            $globals = $this->coercer->globals($set, $command, $opts);
            $arguments = $this->coercer->arguments($command, $opts, $args);
        } catch (UsageError $e) {
            $output->err(Diagnostic::line('error: ' . $e->getMessage()) . "\n");

            return $e->exitCode;
        } catch (InternalError $e) {
            // Coercion can raise one — a preg that fails rather than not matching — and this try
            // used to catch only UsageError, so it escaped uncaught from the phase whose whole job
            // is to keep engine errors away from the user. Reported exactly as dispatch reports it.
            $output->err(Diagnostic::line('internal error: ' . $e->getMessage()) . "\n");

            return self::EXIT_INTERNAL;
        }

        return $this->dispatch($set, $command, $globals, $arguments, $output);
    }

    /**
     * @param array<string, mixed> $globals
     * @param list<mixed> $arguments
     */
    private function dispatch(SetInfo $set, CommandInfo $command, array $globals, array $arguments, Output $output): int
    {
        try {
            // EVERY global, not only the ones that apply: a marked command's --confirm used to stay
            // set on the instance for the next, unmarked command, and repeated in-process handle()
            // calls are something this package supports on purpose. Through reflection rather than
            // `$this->set->{$name} = …`, because a dynamic property write is invisible to static
            // analysis and these properties are declared.
            foreach ($set->globals as $global) {
                $global->property?->setValue($this->set, $globals[$global->phpName] ?? $global->default);
            }

            $invocation = new Invocation($command->name, $command->method, $arguments, $output);
            $this->coercer->assertCallable($command->method, $arguments);

            $this->set->bindInvocation($invocation, $output);

            try {
                $result = $this->pipeline($invocation);
            } finally {
                $this->set->bindInvocation(null, null);

                // Left as it was found, so what the set holds after a call is what it declared.
                foreach ($set->globals as $global) {
                    $global->property?->setValue($this->set, $global->default);
                }
            }
        } catch (UsageError $e) {
            $output->err(Diagnostic::line('error: ' . $e->getMessage()) . "\n");

            return $e->exitCode;
        } catch (Stop $e) {
            $result = $e->result;
        } catch (InternalError $e) {
            $output->err(Diagnostic::line('internal error: ' . $e->getMessage()) . "\n");

            return self::EXIT_INTERNAL;
        } catch (DeclarationError $e) {
            // Nothing catches this one — it names a symbol in the consumer's own command set and
            // must reach the first run as a fatal. #[CatchAs(DeclarationError::class)] is refused at
            // introspection, but a mapping of any ANCESTOR (LogicException, Exception) would
            // otherwise reach it here and report `CommandResult::nothing(999)` as that mapping's
            // considered no — an exit code the declaration never chose, from a declaration that
            // cannot work.
            throw $e;
        } catch (Throwable $e) {
            $mapped = $set->catchFor($e);
            if ($mapped === null) {
                // A fault stays a fault. Turning an unforeseen exception into a tidy exit code is
                // how a broken deployment comes to look like a clean refusal.
                throw $e;
            }
            $output->err(Diagnostic::line(sprintf($mapped->format, $e->getMessage())) . "\n");

            return $mapped->exitCode;
        }

        return $this->emit($result, $output);
    }

    private function pipeline(Invocation $invocation): CommandResult
    {
        // invokeArgs() returns mixed. A command body that forgets to return says so here, plainly,
        // instead of failing later inside the renderer where the cause is no longer visible.
        $next = function () use ($invocation): CommandResult {
            $result = $invocation->method->invokeArgs($this->set, $invocation->arguments);
            if (! $result instanceof CommandResult) {
                throw new InternalError(sprintf(
                    '%s::%s() must return a CommandResult, got %s.',
                    $invocation->method->getDeclaringClass()->getName(),
                    $invocation->method->getName(),
                    get_debug_type($result),
                ));
            }

            return $result;
        };

        foreach (array_reverse($this->set->middlewareStack()) as $layer) {
            $inner = $next;
            $next = static fn (): CommandResult => $layer->handle($invocation, $inner);
        }

        return $next();
    }

    private function emit(CommandResult $result, Output $output): int
    {
        // Encoded BEFORE a single byte is written. json_encode() returning false used to produce a
        // lone newline on stdout and exit 0 — a silent empty success, which is precisely the
        // failure a machine-readable stdout cannot signal. JSON_THROW_ON_ERROR makes it a fault,
        // and doing it first means that fault cannot leave a notice on stderr with nothing under it.
        $payload = $result->text;
        if ($payload === null && $result->hasJson) {
            $payload = json_encode($result->json, $this->jsonFlags | JSON_THROW_ON_ERROR) . "\n";
        }

        foreach ($result->notices as $line) {
            $output->err(Diagnostic::line($line) . "\n");
        }

        if ($payload !== null) {
            $output->out($payload);
        }

        foreach ($result->warnings as $line) {
            $output->err(Diagnostic::line($line) . "\n");
        }

        return $result->exitCode;
    }

    /**
     * @param list<string> $args
     * @param array<string, string|true> $opts
     * @return string|null|false The command to describe, null for the whole script, false if this
     *     is not a help request at all.
     */
    private function helpTopic(SetInfo $set, array $args, array $opts): string|null|false
    {
        if (! $set->meta->single && ($args[0] ?? null) === 'help') {
            $topic = $args[1] ?? null;
            if ($topic !== null && ! $set->has($topic)) {
                throw new UsageError("unknown command '{$topic}'. Accepted: " . implode(', ', $set->names()) . '.');
            }
            if (count($args) > 2) {
                throw new UsageError('help describes one command. Run: help ' . ($topic ?? '<command>'));
            }
            // This branch used to return before it had looked at the options at all, so
            // `help save --help=false` and `help --bogus` both rendered help and exited 0.
            if ($opts !== []) {
                throw new UsageError('help takes no options');
            }

            return $topic;
        }

        if (! array_key_exists('help', $opts)) {
            return false;
        }

        // Built-in help is a flag like any other, and `--help=false` reads as "do not show help" to
        // everyone who types it. Printing help and exiting 0 for it is the same class of defect as
        // `--confirm=false` writing: an answer nobody asked for, reported as success.
        if ($opts['help'] !== true) {
            throw new UsageError('--help is a flag and takes no value');
        }

        // `<command> --help` describes that command, and is answered before validation so it works
        // without the options the command would otherwise require.
        $named = $args[0] ?? null;
        if ($set->meta->single || $named === null) {
            return null;
        }
        if (! $set->has($named)) {
            throw new UsageError("unknown command '{$named}'. Accepted: " . implode(', ', $set->names()) . '.');
        }

        return $named;
    }

    private function empty(SetInfo $set, Output $output, string $program): int
    {
        if ($set->meta->onEmpty === WhenEmpty::Error) {
            $message = $set->meta->emptyMessage ?? "nothing to do. Run: {$program} --help";
            $output->err(Diagnostic::line('error: ' . $message) . "\n");

            return 1;
        }

        $output->out($this->help->render($set, null, $program));

        return $set->meta->onEmpty === WhenEmpty::HelpFailed ? 1 : 0;
    }

    /** @param list<string> $args */
    private function resolve(SetInfo $set, array &$args, string $program): CommandInfo
    {
        if ($set->meta->single) {
            return $set->only();
        }

        $name = (string) array_shift($args);
        if (! $set->has($name)) {
            throw new UsageError(sprintf(
                "unknown command '%s'. Accepted: %s. Run: %s",
                $name,
                implode(', ', $set->names()),
                $program . ' help',
            ));
        }

        return $set->get($name);
    }

    /** Whose options a help request is measured against: the command it describes, or the set. */
    private function helpSubject(SetInfo $set, ?string $topic): ?CommandInfo
    {
        if ($topic !== null) {
            return $set->get($topic);
        }

        return $set->meta->single ? $set->only() : null;
    }

    /**
     * An option the command does not take is an error, never a no-op.
     *
     * A filter that silently fails to apply returns everything — which is exactly what a filter
     * matching everything returns, so there is nothing in the output to notice.
     *
     * @param CommandInfo|null $command Null for script-level help on a multi-command set, where no
     *     command has been named — so only the globals that apply to every one of them are offered,
     *     which is exactly the set the overview help prints.
     * @param array<string, string|true> $opts
     */
    private function assertKnownOptions(SetInfo $set, ?CommandInfo $command, array $opts, string $program): void
    {
        $accepted = ['help'];
        foreach ($command?->options() ?? [] as $option) {
            $accepted[] = $option->cliName;
        }
        foreach ($command === null ? $set->unconditionalGlobals() : $set->globalsFor($command) as $global) {
            $accepted[] = $global->cliName;
        }

        $unknown = array_values(array_diff(array_keys($opts), $accepted));
        if ($unknown === []) {
            return;
        }

        sort($accepted);

        throw new UsageError(sprintf(
            'unknown option%s %s%s. Accepted: %s. Run: %s',
            count($unknown) === 1 ? '' : 's',
            implode(', ', array_map(static fn (string $o): string => '--' . $o, $unknown)),
            $set->meta->single || $command === null ? '' : " for '{$command->name}'",
            implode(', ', array_map(static fn (string $o): string => '--' . $o, $accepted)),
            $set->meta->single ? $program . ' --help' : $program . ' help',
        ));
    }
}
