<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

use Espego\CliRouter\Arg;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\Opt;
use Espego\CliRouter\WhenEmpty;

/** A single-command set with a variadic positional — no subcommand word, no dependencies. */
#[Cli(summary: 'Check files.', single: true, onEmpty: WhenEmpty::Error, emptyMessage: 'no file given.')]
final class FileSet extends Commands
{
    #[Command('Audit one or more files.')]
    public function commandRun(
        #[Opt('Report as JSON.')]
        bool $json = false,
        /** A declared non-zero code, so a test can prove the shell is handed it rather than 0. */
        #[Opt('Report the check as failed.')]
        bool $fail = false,
        #[Arg('A file to check.', placeholder: 'file.pdf', required: true)]
        string ...$files,
    ): CommandResult {
        return CommandResult::json([
            'json' => $json,
            'files' => $files,
        ], $fail ? 3 : 0);
    }
}
