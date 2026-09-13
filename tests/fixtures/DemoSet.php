<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espego\CliRouter\CatchAs;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\IntList;
use Espego\CliRouter\Opt;
use Espego\CliRouter\StringList;

/** A multi-command set exercising globals, enums, dates, lists, a write guard and an exception map. */
#[Cli(summary: 'Demo CLI.', groups: ['Read', 'Write'], after: 'Footer prose.')]
#[CatchAs(Refused::class, exitCode: 4, format: 'refused: %s')]
final class DemoSet extends Commands
{
    #[Opt('Print the raw response.')]
    public bool $raw = false;

    #[Opt('Actually write.', onlyWhen: Mutates::class)]
    public bool $confirm = false;

    public function __construct(
        private readonly string $host = 'demo.local'
    ) {
    }

    protected function middleware(): array
    {
        return [new Trace()];
    }

    protected function dateTimeZone(): DateTimeZone
    {
        return new DateTimeZone('Europe/Prague');
    }

    #[Command(summary: 'Say which host.', group: 'Read')]
    public function commandEnv(): CommandResult
    {
        return CommandResult::json([
            'host' => $this->host,
            'raw' => $this->raw,
        ]);
    }

    #[Command(summary: 'Filter by number.', group: 'Read')]
    public function commandContracts(
        #[Opt('Contract number.', placeholder: 'nr')]
        ?string $nr = null,
    ): CommandResult {
        return CommandResult::json([
            'nr' => $nr,
        ]);
    }

    // No docblock and no `of:` — StringList and IntList state their element type themselves, so
    // the signature is the only place it is written.
    #[Command(summary: 'Comma-separated values.', group: 'Read')]
    public function commandEvents(
        #[Opt('MSA numbers.', placeholder: 'msaNr', minCount: 1)]
        StringList $msa,
        #[Opt('Numeric ids.', placeholder: 'id')]
        ?IntList $ids = null,
        #[Opt('Colours.')]
        ?NoteTypeList $colours = null,
    ): CommandResult {
        return CommandResult::json([
            'msa' => $msa->all(),
            'ids' => $ids?->all(),
            'colours' => $colours === null ? null : array_map(
                static fn (Colour $c): string => $c->value,
                $colours->all()
            ),
        ]);
    }

    #[Command(summary: 'Typed values.', group: 'Read')]
    public function commandTyped(
        #[Opt('A colour.')]
        Colour $colour,
        #[Opt('A count.', min: 1, max: 10)]
        int $count = 1,
        #[Opt('A moment.', placeholder: 'ATOM')]
        ?DateTimeImmutable $at = null,
        #[Opt('A date.', placeholder: 'Y-m-d', pattern: '/^\d{4}-\d{2}-\d{2}$/u', hint: 'No other format is accepted.')]
        ?string $on = null,
        #[Opt('Free text.', allowEmpty: true, trim: false)]
        string $note = '',
    ): CommandResult {
        return CommandResult::json([
            'colour' => $colour->value,
            'count' => $count,
            'at' => $at?->format(DATE_ATOM),
            'on' => $on,
            'note' => $note,
        ]);
    }

    #[Command(summary: 'Text output, custom exit code.', group: 'Read')]
    public function commandReport(
        #[Opt('Exit with this instead of 0.', placeholder: 'n')]
        int $code = 0,
    ): CommandResult {
        return CommandResult::text("a report\n", $code);
    }

    #[Command(summary: 'Write something.', group: 'Write')]
    #[Mutates]
    public function commandSave(#[Opt('What to save.')] string $what): CommandResult
    {
        $preview = [
            'what' => $what,
        ];

        if (! $this->confirm) {
            return CommandResult::json($preview, 2)->withNotice('dry run — nothing written.');
        }
        if ($what === 'boom') {
            throw new Refused('that one is already saved.');
        }

        return CommandResult::json([
            'saved' => $what,
        ])
            ->withWarning('warning: could not verify by reading it back.');
    }

    #[Command(summary: 'Name the running command without a literal.', group: 'Write')]
    #[Mutates]
    public function commandRename(): CommandResult
    {
        return CommandResult::json([
            'command' => $this->invocation()->name,
        ]);
    }

    #[Command(summary: 'Fail from a helper.', group: 'Read')]
    public function commandNope(): CommandResult
    {
        $this->reject();
    }

    #[Command(summary: 'Throw something undeclared.', group: 'Read')]
    public function commandFault(): CommandResult
    {
        throw new \OutOfRangeException('a genuine fault');
    }

    private function reject(): never
    {
        $this->fail('refused by a helper three frames down');
    }
}
