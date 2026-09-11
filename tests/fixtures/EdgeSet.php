<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

use Espego\CliRouter\Arg;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\Opt;

/** The declarations the review's reproductions used. */
#[Cli(summary: 'Edges.')]
final class EdgeSet extends Commands
{
    #[Opt('Actually write.')]
    public bool $confirm = false;

    /** json_encode cannot represent INF; the result must not be a silent empty success. */
    #[Command('Return something unencodable.')]
    public function commandUnencodable(): CommandResult
    {
        return CommandResult::json(INF);
    }

    #[Command('Take an int-backed enum.')]
    public function commandLevel(#[Opt('How loud.')] Level $level): CommandResult
    {
        return CommandResult::json($level->value);
    }

    #[Command('Take a list of int-backed enums.')]
    public function commandLevels(#[Opt('How loud, repeatedly.')] LevelList $levels): CommandResult
    {
        return CommandResult::json(array_map(static fn (Level $l): int => $l->value, $levels->all()));
    }

    #[Command('Report whether it was confirmed.')]
    public function commandSave(#[Opt('What to save.')] string $what): CommandResult
    {
        return CommandResult::json([
            'what' => $what,
            'confirm' => $this->confirm,
        ]);
    }

    #[Command('Echo the positionals it was given.')]
    public function commandFiles(
        #[Opt('Unrelated flag.')]
        bool $json = false,
        #[Arg('A path.', placeholder: 'path')]
        string ...$paths,
    ): CommandResult {
        return CommandResult::json([
            'json' => $json,
            'paths' => $paths,
        ]);
    }

    #[Command('Name the running command.')]
    public function commandWhoami(): CommandResult
    {
        return CommandResult::json($this->invocation()->name);
    }
}
