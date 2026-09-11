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
        $program = 'php ' . ($argv[0] ?? 'script.php');
        $set = $this->introspector->set($this->set);

        try {
            ['args' => $args, 'opts' => $opts] = (new ArgumentParser())->parse(array_slice($argv, 1));

            $topic = $this->helpTopic($set, $args, $opts);
            if ($topic !== false) {
                $output->out($this->help->render($set, $topic, $program));

                return 0;
            }

            if ($args === [] && $opts === []) {
                return $this->empty($set, $output, $program);
            }

            $command = $this->resolve($set, $args, $program);
            $this->assertKnownOptions($set, $command, $opts, $program);

            $globals = $this->coercer->globals($set, $command, $opts);
            $arguments = $this->coercer->arguments($command, $opts, $args);
        } catch (UsageError $e) {
            $output->err('error: ' . $e->getMessage() . "\n");

            return $e->exitCode;
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
            // Through reflection rather than `$this->set->{$name} = …`: a dynamic property write is
            // invisible to static analysis, and these properties are declared — there is no reason
            // to hide them from it.
            foreach ($set->globalsFor($command) as $global) {
                $global->property?->setValue($this->set, $globals[$global->phpName]);
            }

            $invocation = new Invocation($command->name, $command->method, $arguments, $output);
            $this->set->bindInvocation($invocation, $output);

            $this->coercer->assertCallable($command->method, $arguments);

            $result = $this->pipeline($invocation);
        } catch (UsageError $e) {
            $output->err('error: ' . $e->getMessage() . "\n");

            return $e->exitCode;
        } catch (Stop $e) {
            $result = $e->result;
        } catch (InternalError $e) {
            $output->err('internal error: ' . $e->getMessage() . "\n");

            return self::EXIT_INTERNAL;
        } catch (Throwable $e) {
            $mapped = $set->catchFor($e);
            if ($mapped === null) {
                // A fault stays a fault. Turning an unforeseen exception into a tidy exit code is
                // how a broken deployment comes to look like a clean refusal.
                throw $e;
            }
            $output->err(sprintf($mapped->format, $e->getMessage()) . "\n");

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
        foreach ($result->notices as $line) {
            $output->err($line . "\n");
        }

        if ($result->text !== null) {
            $output->out($result->text);
        } elseif ($result->hasJson) {
            $output->out(json_encode($result->json, $this->jsonFlags) . "\n");
        }

        foreach ($result->warnings as $line) {
            $output->err($line . "\n");
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
        $asked = isset($opts['help']);

        if (! $set->meta->single && ($args[0] ?? null) === 'help') {
            $topic = $args[1] ?? null;
            if ($topic !== null && ! $set->has($topic)) {
                throw new UsageError("unknown command '{$topic}'. Accepted: " . implode(', ', $set->names()) . '.');
            }

            return $topic;
        }

        if (! $asked) {
            return false;
        }

        // `<command> --help` describes that command, and is answered before validation so it works
        // without the options the command would otherwise require.
        $named = $args[0] ?? null;
        if (! $set->meta->single && $named !== null && $set->has($named)) {
            return $named;
        }

        return null;
    }

    private function empty(SetInfo $set, Output $output, string $program): int
    {
        if ($set->meta->onEmpty === WhenEmpty::Error) {
            $output->err('error: ' . ($set->meta->emptyMessage ?? "nothing to do. Run: {$program} --help") . "\n");

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

    /**
     * An option the command does not take is an error, never a no-op.
     *
     * A filter that silently fails to apply returns everything — which is exactly what a filter
     * matching everything returns, so there is nothing in the output to notice.
     *
     * @param array<string, string|true> $opts
     */
    private function assertKnownOptions(SetInfo $set, CommandInfo $command, array $opts, string $program): void
    {
        $accepted = ['help'];
        foreach ($command->options() as $option) {
            $accepted[] = $option->cliName;
        }
        foreach ($set->globalsFor($command) as $global) {
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
            $set->meta->single ? '' : " for '{$command->name}'",
            implode(', ', array_map(static fn (string $o): string => '--' . $o, $accepted)),
            $set->meta->single ? $program . ' --help' : $program . ' help',
        ));
    }
}
