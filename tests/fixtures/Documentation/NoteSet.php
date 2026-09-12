<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests\Documentation;

use Espego\CliRouter\Arg;
use Espego\CliRouter\CatchAs;
use Espego\CliRouter\Cli;
use Espego\CliRouter\Command;
use Espego\CliRouter\CommandResult;
use Espego\CliRouter\Commands;
use Espego\CliRouter\Opt;
use Espego\CliRouter\StringList;

/**
 * A worked example: a dispatching set with a read half, a gated write half, and one mapped
 * exception. Copy it and change the domain.
 *
 * The package's own test suite runs this class. The documentation generator copies this exact
 * source into the distributed worked example.
 */
#[Cli(
    summary: 'Keep a short list of notes.',
    groups: ['Read', 'Write'],
    after: 'A write command previews what it would do and stops. --confirm carries it out.',
)]
#[CatchAs(Rejected::class, exitCode: 4, format: 'rejected: %s')]
final class NoteSet extends Commands
{
    #[Opt('Carry the write out instead of previewing it.', onlyWhen: Writes::class)]
    public bool $confirm = false;

    /** What the script needs, typed, one per parameter — no container, no service locator. */
    public function __construct(
        private readonly NoteStore $store = new NoteStore()
    ) {
    }

    #[Command(summary: 'List notes, newest first.', group: 'Read')]
    public function commandList(
        #[Opt('Only notes of this priority.')]
        ?Priority $priority = null,
        #[Opt('Only notes carrying every one of these tags.', placeholder: 'tag')]
        ?StringList $tag = null,
        #[Opt('At most this many.', placeholder: 'n', min: 1, max: 100)]
        int $limit = 20,
    ): CommandResult {
        return CommandResult::json($this->store->find($priority, $tag?->all() ?? [], $limit));
    }

    #[Command(summary: 'Print one note.', group: 'Read')]
    public function commandShow(
        #[Arg('The note to print.', placeholder: 'id', min: 1)]
        int $id,
    ): CommandResult {
        return CommandResult::json($this->store->get($id));
    }

    #[Command(summary: 'Add a note.', group: 'Write')]
    #[Writes]
    public function commandAdd(
        #[Arg('The text of the note.', placeholder: 'text')]
        string $text,
        #[Opt('How urgent it is.')]
        Priority $priority = Priority::Normal,
        #[Opt('Tags to file it under.', placeholder: 'tag', maxCount: 5)]
        ?StringList $tag = null,
    ): CommandResult {
        $tags = $tag?->all() ?? [];

        if (! $this->confirm) {
            return CommandResult::json([
                'text' => $text,
                'priority' => $priority->value,
                'tags' => $tags,
            ], 2)
                ->withNotice('preview — nothing written. Repeat with --confirm.');
        }

        return CommandResult::json($this->store->add($text, $priority, $tags));
    }

    #[Command(
        summary: 'Change some fields of a note.',
        description: 'Only the fields you name are touched.',
        group: 'Write',
    )]
    #[Writes]
    public function commandEdit(
        #[Arg('The note to change.', placeholder: 'id', min: 1)]
        int $id,
        #[Opt('Replace the text.')]
        ?string $text = null,
        #[Opt('Replace the priority.')]
        ?Priority $priority = null,
        // Nullable and allowEmpty together are what make `--detail=` mean "remove it" while
        // omitting the option means "leave it alone". Declared `string $detail = ''` those two
        // become one value, and `--detail=` would be refused outright.
        #[Opt('Replace the long-form detail. --detail= removes it.', allowEmpty: true)]
        ?string $detail = null,
        #[Opt('Replace the due date.', placeholder: 'Y-m-d', pattern: '/^\d{4}-\d{2}-\d{2}$/u', hint: 'No other format is accepted.')]
        ?string $due = null,
    ): CommandResult {
        $changes = array_filter(
            [
                'text' => $text,
                'priority' => $priority?->value,
                'detail' => $detail,
                'due' => $due,
            ],
            static fn (?string $v): bool => $v !== null
        );

        // A rule the declaration genuinely cannot state: any one of four options will do, but not
        // none of them. That is what fail() is for.
        if ($changes === []) {
            $this->fail('name at least one of --text, --priority, --detail, --due.');
        }

        if (! $this->confirm) {
            return CommandResult::json([
                'id' => $id,
                'changes' => $changes,
            ], 2)
                ->withNotice('preview — nothing written. Repeat with --confirm.');
        }

        return CommandResult::json($this->store->edit($id, $changes));
    }
}
