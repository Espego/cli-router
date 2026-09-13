<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

use Espego\CliRouter\Arg;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\Opt;
use Espego\CliRouter\StringList;

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
    public function commandLevels(#[Opt('How loud, repeatedly.', minCount: 1)] LevelList $levels): CommandResult
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

    /** Cardinality lives in minCount/maxCount, where it used to be min/max doing double duty. */
    #[Command('Take between two and three tags.')]
    public function commandTags(
        #[Opt('A tag.', placeholder: 'tag', minCount: 2, maxCount: 3)]
        StringList $tags,
    ): CommandResult {
        return CommandResult::json($tags->all());
    }

    /** trim: false used to apply to the whole value and not to the elements it was split into. */
    #[Command('Take a list without trimming it.')]
    public function commandLoose(
        #[Opt('An untrimmed element.', placeholder: 'raw', minCount: 0, trim: false)]
        StringList $loose,
    ): CommandResult {
        return CommandResult::json($loose->all());
    }

    /** Writes through output(), which is only answerable while a command is actually running. */
    #[Command('Write a line as it goes.')]
    public function commandSpeak(): CommandResult
    {
        $this->output()->out("spoken\n");

        return CommandResult::nothing();
    }

    #[Command('Name the running command.')]
    public function commandWhoami(): CommandResult
    {
        return CommandResult::json($this->invocation()->name);
    }
}
