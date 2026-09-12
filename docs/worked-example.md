# Worked example

This is one complete command set, its small in-memory collaborator, a canonical test pattern and
the help that test asserts byte for byte. Read it after `docs/writing-a-command-set.md` when the
individual declarations make sense but their composition does not yet.

The code blocks are generated from the package's tested fixtures. In the package's source checkout,
do not edit anything between the `GENERATED` markers directly; `make docs` replaces it. The PHP
files themselves are development fixtures and do not ship. When adapting the pattern, replace its
test namespace, bootstrap, test runner, collaborator, commands and assertions with the consuming
project's own.

## Command set

<!-- BEGIN GENERATED: note-set -->
```php
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
```
<!-- END GENERATED: note-set -->

## Supporting types

The enum carries its accepted values. The marker connects write commands to their global
`--confirm` option. The exception represents a considered refusal that may become a stable CLI
result rather than a crash.

<!-- BEGIN GENERATED: priority -->
```php
<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests\Documentation;

/**
 * A backed enum validates itself and prints its own `Allowed:` list, so neither the body nor the
 * help ever restates which values exist.
 */
enum Priority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
}
```
<!-- END GENERATED: priority -->

<!-- BEGIN GENERATED: writes -->
```php
<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests\Documentation;

use Attribute;

/**
 * Marks a command as one that changes something.
 *
 * The package never names this attribute; `#[Opt(onlyWhen: Writes::class)]` on the set's
 * `--confirm` global is what connects the two. That is what makes the write gate a declaration
 * rather than a convention every new command has to remember.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Writes
{
}
```
<!-- END GENERATED: writes -->

<!-- BEGIN GENERATED: rejected -->
```php
<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests\Documentation;

use RuntimeException;

/** The store's considered "no" — a result, not a fault, so `#[CatchAs]` may map it. */
final class Rejected extends RuntimeException
{
}
```
<!-- END GENERATED: rejected -->

The store stands in for an injected database, API client or application service. Its implementation
is intentionally ordinary; the command set depends on it through its constructor and contains no
service lookup.

<!-- BEGIN GENERATED: note-store -->
```php
<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tests\Documentation;

/**
 * Stands in for whatever a real script talks to — a database, an HTTP API, a file.
 *
 * In memory so the example runs anywhere, and so a test can hand the set a store it seeded itself
 * rather than reaching for a mocking library.
 *
 * @phpstan-type NoteRow array{id: int, text: string, priority: string, tags: list<string>, detail: string|null, due: string|null}
 */
final class NoteStore
{
    /** @var array<int, NoteRow> */
    private array $notes = [];

    private int $nextId = 1;

    /** @param list<NoteRow> $notes */
    public function __construct(array $notes = self::SEED)
    {
        foreach ($notes as $note) {
            $this->notes[$note['id']] = $note;
            $this->nextId = max($this->nextId, $note['id'] + 1);
        }
    }

    /**
     * @param list<string> $tags Every one of them must be present, not any.
     * @return list<NoteRow>
     */
    public function find(?Priority $priority, array $tags, int $limit): array
    {
        $matched = array_filter(
            $this->notes,
            static fn (array $n): bool => ($priority === null || $n['priority'] === $priority->value)
                && array_diff($tags, $n['tags']) === []
        );
        krsort($matched);

        return array_slice(array_values($matched), 0, $limit);
    }

    /** @return NoteRow */
    public function get(int $id): array
    {
        return $this->notes[$id] ?? throw new Rejected("there is no note {$id}.");
    }

    /**
     * @param list<string> $tags
     * @return NoteRow
     */
    public function add(string $text, Priority $priority, array $tags): array
    {
        $note = [
            'id' => $this->nextId++,
            'text' => $text,
            'priority' => $priority->value,
            'tags' => $tags,
            'detail' => null,
            'due' => null,
        ];
        $this->notes[$note['id']] = $note;

        return $note;
    }

    /**
     * @param array<string, mixed> $changes Only the keys given are touched.
     * @return NoteRow
     */
    public function edit(int $id, array $changes): array
    {
        $note = array_merge($this->get($id), $changes);
        /** @var NoteRow $note */
        $this->notes[$id] = $note;

        return $note;
    }

    /** @var list<NoteRow> */
    private const array SEED = [
        [
            'id' => 1,
            'text' => 'Renew the domain',
            'priority' => 'high',
            'tags' => ['ops', 'billing'],
            'detail' => null,
            'due' => '2026-10-01',
        ],
        [
            'id' => 2,
            'text' => 'Write the release notes',
            'priority' => 'normal',
            'tags' => ['docs'],
            'detail' => 'Cover the parser change.',
            'due' => null,
        ],
        [
            'id' => 3,
            'text' => 'Archive the old invoices',
            'priority' => 'low',
            'tags' => ['billing'],
            'detail' => null,
            'due' => null,
        ],
    ];
}
```
<!-- END GENERATED: note-store -->

## Canonical test pattern

Keep the three layers when adapting this test: call bodies directly, drive the complete CLI through
`handle()`, and compare generated help byte for byte. Preserve declaration-specific negative cases;
the consuming project does not need to repeat every parser test owned by this package.

<!-- BEGIN GENERATED: test -->
```php
<?php

declare(strict_types=1);

/**
 * The three ways a command set is worth testing. The distributed worked example copies this exact
 * source as a pattern: consumers adapt its bootstrap, imports, fixtures, commands and assertions.
 */

require __DIR__ . '/../bootstrap.php';

use Espego\CliRouter\BufferedOutput;
use Espego\CliRouter\StringList;
use Espego\CliRouter\Tests\Documentation\NoteSet;
use Espego\CliRouter\Tests\Documentation\NoteStore;
use Espego\CliRouter\Tests\Documentation\Priority;
use Tester\Assert;

/** Explicit test data, so this case depends on no hidden or shared fixture state. */
function set(): NoteSet
{
	return new NoteSet(new NoteStore([
		['id' => 7, 'text' => 'Renew the domain', 'priority' => 'high', 'tags' => ['ops'], 'detail' => 'Before October.', 'due' => '2026-10-01'],
	]));
}

/** @return array{int, string, string} exit code, stdout, stderr */
function cli(string ...$argv): array
{
	$out = new BufferedOutput();
	$code = set()->handle(['notes.php', ...$argv], $out);

	return [$code, $out->out, $out->err];
}


// ── 1. The body, called directly with named arguments. No process, no argv, no parsing. ──

$result = set()->commandAdd('Book the room', priority: Priority::High, tag: StringList::of(['ops']));
Assert::same(2, $result->exitCode, 'a write without --confirm previews and says so in the exit code');
Assert::same(['preview — nothing written. Repeat with --confirm.'], $result->notices);

$confirmed = set();
$confirmed->confirm = true;
Assert::same(0, $confirmed->commandAdd('Book the room')->exitCode);


// ── 2. The whole CLI, through handle(), asserting the exit code and the two streams apart. ──

[$code, $out, $err] = cli('show', '7');
Assert::same(0, $code);
Assert::same('', $err);
Assert::contains('"text": "Renew the domain"', $out);

// An unknown option is answered before a command body exists.
[$code, $out, $err] = cli('show', '7', '--verbos');
Assert::same(1, $code);
Assert::same('', $out, 'a usage error writes nothing to stdout');
Assert::contains('unknown option --verbos', $err);

// A mapped exception is the store's considered no: its own exit code, one line, no stack trace.
[$code, , $err] = cli('show', '99');
Assert::same(4, $code);
Assert::same("rejected: there is no note 99.\n", $err);

// fail(), raised from the body for a rule no declaration could carry.
[$code, , $err] = cli('edit', '7');
Assert::same(1, $code);
Assert::contains('name at least one of', $err);

// --confirm exists only on commands carrying #[Writes].
[$code, , $err] = cli('list', '--confirm');
Assert::same(1, $code);
Assert::contains('unknown option --confirm', $err);


// ── 3. Not given is not the same as given empty. ──

// Called directly: an empty string passed for $detail is a change; omitting it is not.
Assert::same(['id' => 7, 'changes' => ['detail' => '']], set()->commandEdit(7, detail: '')->json);
Assert::same(['id' => 7, 'changes' => ['text' => 'Renew it']], set()->commandEdit(7, text: 'Renew it')->json);

// Through argv, the same distinction: `--detail=` is a value, so the field is cleared.
[$code, $out] = cli('edit', '7', '--detail=', '--confirm');
Assert::same(0, $code);
Assert::contains('"detail": ""', $out);

// Omitting it leaves the field alone. Only `?string $detail = null` tells the two apart —
// `string $detail = ''` would refuse `--detail=` outright.
[$code, $out] = cli('edit', '7', '--text=Renew it', '--confirm');
Assert::same(0, $code);
Assert::contains('"detail": "Before October."', $out);


// ── 4. The help, byte for byte, against a checked-in file. ──

// A substring assertion is not a substitute: it passes just as happily when a command has
// silently vanished, a column has moved, or the output has been truncated.
[$code, $out, $err] = cli('help');
Assert::same(0, $code);
Assert::same('', $err, 'help belongs on stdout, so `--help | less` works');
Assert::matchFile(__DIR__ . '/DocumentationExampleHelp.expect', $out);

// `help` and `--help` are the same page.
Assert::same($out, cli('--help')[1]);
```
<!-- END GENERATED: test -->

## Expected help

<!-- BEGIN GENERATED: help -->
```text
Keep a short list of notes.

  php notes.php <command> [options]
  php notes.php help [<command>]

Read:
  list [--priority=<low|normal|high>] [--tag=<tag>[,<tag>...]] [--limit=<n>]
                        List notes, newest first.
  show <id>             Print one note.

Write:
  add <text> [--priority=<low|normal|high>] [--tag=<tag>[,<tag>...]]
                        Add a note.
  edit <id> [--text=<text>] [--priority=<low|normal|high>] [--detail=<detail>]
       [--due=<Y-m-d>]
                        Change some fields of a note.

Options:
  --help                 Print this.

A write command previews what it would do and stops. --confirm carries it out.
```
<!-- END GENERATED: help -->
