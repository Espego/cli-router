<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use Throwable;

/** @internal Not part of the public surface; may change in any release. */
final class SetInfo
{
    /**
     * @param array<string, CommandInfo> $commands
     * @param list<ValueSpec> $globals
     * @param list<CatchAs> $catches
     */
    public function __construct(
        public readonly Cli $meta,
        public readonly array $commands,
        public readonly array $globals,
        public readonly array $catches,
    ) {
    }

    public function has(string $name): bool
    {
        return isset($this->commands[$name]);
    }

    public function get(string $name): CommandInfo
    {
        return $this->commands[$name] ?? throw new InternalError("no command '{$name}'");
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->commands);
    }

    /** The single command of a `single: true` set. */
    public function only(): CommandInfo
    {
        return array_values($this->commands)[0] ?? throw new InternalError('the set declares no command');
    }

    /**
     * Globals that apply to this command: the unconditional ones, plus any whose `onlyWhen` marker
     * the command carries.
     *
     * @return list<ValueSpec>
     */
    public function globalsFor(CommandInfo $command): array
    {
        return array_values(array_filter($this->globals, static function (ValueSpec $g) use ($command): bool {
            $gate = $g->gate();

            return $gate === null || $command->marked($gate);
        }));
    }

    /**
     * Globals that apply whatever is run — the only ones a multi-command overview can speak for,
     * since no command has been named there and a gated one is untrue of most of them.
     *
     * The help prints this set and the runner accepts this set. They were two filters, and the one
     * that was missing let `--help --confirm` pass with a help text that does not mention --confirm.
     *
     * @return list<ValueSpec>
     */
    public function unconditionalGlobals(): array
    {
        return array_values(array_filter($this->globals, static fn (ValueSpec $g): bool => $g->gate() === null));
    }

    public function catchFor(Throwable $e): ?CatchAs
    {
        foreach ($this->catches as $catch) {
            if (is_a($e, $catch->exception)) {
                return $catch;
            }
        }

        return null;
    }
}
