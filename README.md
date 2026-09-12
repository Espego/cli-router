# espego/cli-router

A command is a method. Its parameters are its options. Its attributes are its help.

Everything else — the accepted-option list, the usage text, the unknown-option rejection, the
`$command === null` / `fwrite(STDERR, …)` / `exit(1)` chain — is generated from the signature, so
it cannot drift from the code it describes.

Two requirements: PHP 8.4 and `ext-mbstring`, which the help renderer counts characters with.

---

> **Internal to Espego.** This is published so our own projects can pull it, not as a package for
> general use. It is licensed `proprietary` — you do not have permission to use it. There is no
> support, no issue triage, and no commitment to backwards compatibility: it is versioned `0.x`
> precisely because the API will change whenever our own needs change, without notice or a
> migration path. If it looks useful, copy the idea rather than depending on the package.

---

## The whole thing

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

(new App\Cli\Pdf\CommandSet)->run();
```

```php
#[Cli('Print an HTML file to PDF with headless Chrome.', single: true)]
final class CommandSet extends Commands
{
    public function __construct(private readonly PdfRenderer $renderer = new PdfRenderer()) {}

    #[Command('Print one HTML file to PDF.')]
    public function commandRun(
        #[Arg('The file to print.', placeholder: 'file.html')]
        string $file,
        #[Opt('Output path. Defaults to the input name with a .pdf extension.')]
        ?string $out = null,
        #[Opt('Draw the letterhead and set the document title.')]
        ?string $title = null,
        #[Opt('Keep the heading, drop the mark.')]
        bool $noLogo = false,
    ): CommandResult {
        $out ??= preg_replace('/\.html?$/iu', '', $file) . '.pdf';

        return CommandResult::json(['pdf' => $this->renderer->render($file, $out, $title, !$noLogo)]);
    }
}
```

That is a complete CLI: `--help`, a generated usage block, `--titel` rejected with the list of
options that do exist, `--title` with no value rejected, `--no-logo=false` rejected.

## What you declare

| | |
|---|---|
| `#[Cli]` | on the class — the script's summary, synopsis, group headings, footer prose, what to do with no arguments |
| `#[Command]` | on a method — it is a command because it carries this, never because of its name |
| `#[Opt]` | on a parameter (an option) or a public property (a set-wide global) |
| `#[Arg]` | on a parameter — a positional; a variadic takes the rest |
| `#[CatchAs]` | on the class, repeatable — map an exception to an exit code and one stderr line |

Multi-value options are a **type**, not an `array` with a docblock:

```php
#[Opt('MSA numbers.', placeholder: 'msaNr')]
StringList $msa,                    // --msa=IC01,IC02

#[Opt('Instance ids.', placeholder: 'id')]
?IntList $instance = null,
```

`StringList` and `IntList` ship with the package. For a list of one particular enum, name it once:

```php
/** @extends EnumList<NoteType> */
final class NoteTypeList extends EnumList
{
    public static function elementType(): string { return NoteType::class; }
}
```

after which every option of that type is just `NoteTypeList $type`, and `->all()` resolves to
`list<NoteType>`. Elements are validated one at a time, so `--instance=1,x,3` names `'x'` rather
than rejecting the whole value.

Names come from the code: `commandNoteDone()` is `note-done`, `$intendedEnv` is `--intended-env`.
There is no name override, deliberately — one override is all it takes for the help to start
describing a flag that does not exist.

Types come from the signature. `string` is required unless it has a default, `bool` is a flag that
refuses a value, `?DateTimeImmutable` parses, and a backed enum validates itself and prints its own
`Allowed:` list. The attribute carries only what a type genuinely cannot say: the description, a
`pattern` (which must be a `/u` one — arguments arrive as UTF-8), a `min`/`max` for a number, a
`minCount`/`maxCount` for how many elements a list or a variadic may carry, and the `separator` a
list splits on. A bound the type cannot honour is refused rather than ignored: `min` on a `string`,
`minCount` on a scalar, a default outside its own range.

## What the parser does with argv

- `--key=value` and a bare `--flag`. No short options, no `--opt value`.
- **A repeated option is an error, never resolved.** Keeping the last occurrence let a malformed
  `--confirm=false` be rescued by a later bare `--confirm` and the write go ahead, so every
  repeated name is named back: `--what, --confirm were given more than once`.
- **`--` ends option parsing.** Everything after it is positional whatever it looks like, which is
  the only way to pass a path beginning with `--`.
- **argv is text or it is nothing.** Unix hands over bytes; an argument that is not valid UTF-8 is
  refused by position, never by quoting it back. Everything above the parser — the help counting
  characters, a `/u` pattern, the sanitiser diagnostics pass through — assumes text, and used to
  fail in ways that read as something else entirely.

## What happens with no arguments at all

`#[Cli(onEmpty:)]` — `WhenEmpty::Help` (the default, exit 0), `HelpFailed` (the help, exit 1, so a
typo in a wrapper does not look like it worked), `Error` with an `emptyMessage`, or **`Run`**, which
is for the `status` / `sync` / `flush` shape: a `single: true` set whose parameters are all optional,
where being called with nothing is the whole point. Both halves of that are checked at
introspection, so `Run` cannot be declared where it could only ever produce a usage error.

## What a command returns

`CommandResult::json()`, `::text()` or `::nothing()`, with an exit code — plus `withNotice()` for a
line that frames what follows and `withWarning()` for one that qualifies it. Notices print to stderr
before stdout, warnings after, so ordering between the two streams is reproducible.

**An exit code is 0-255, and `fail()`'s is 1-255**, checked where it is constructed rather than
where it is returned. The shell reads one byte: 999 arrives as 231, 256 as success, and a `fail()`
of 0 prints a diagnostic and then reports that all is well.

A command body never calls `exit()` and never writes to `STDERR`. That is what makes it callable
straight from a test:

```php
$result = (new CommandSet($fakeRenderer))->commandRun('x.html', title: 'Výpověď');
Assert::same(0, $result->exitCode);
```

…and what lets the whole CLI be driven without a process:

```php
$out = new BufferedOutput();
Assert::same(1, (new CommandSet)->handle(['bin/pdf.php', 'x.html', '--titel=X'], $out));
Assert::contains('unknown option --titel', $out->err);
```

## Dependencies

The set's constructor lists what the script needs, typed, one per parameter — ordinary
constructor injection, no container and no service locator:

```php
(new App\Cli\Mesa\CommandSet($sysApiClient, $environmentService))->run();
```

A set that needs nothing stays a one-liner. The base class declares no constructor at all, so a
subclass never has to call `parent::__construct()`.

## Ordering

Help, an unknown command, an unknown option and every type, pattern or range failure all resolve
*before* any middleware runs and before a command body exists. A mistyped flag costs an error
message and nothing else, whatever the command would have gone on to do.

## What it refuses

A declaration that cannot work is refused at introspection, naming the symbol at fault, because the
alternative is help text that lies or a limit that reads as enforced and is not. On top of the
unsupported types — a union, a bare `array`, an unknown class, a mutable `DateTime` — it refuses a
`#[Command]` that is private, static, oddly named or does not return `CommandResult`; a parameter
carrying both `#[Arg]` and `#[Opt]`; a positional `bool`, which could never be supplied; a
`ValueList` whose `elementType()` is not one the coercer can produce; a constraint that could never
apply, or contradicts itself, or that the declared default already violates — elements included, so
`IntList([0])` under `min: 1` is refused like the scalar it would be; a flag that already defaults
to true and so can never take another value; and a `#[CatchAs]` whose exit code is outside 1-255,
whose format is not one `%s`, that names something the runner has already handled, or that covers
faults rather than a considered no — `Throwable` and the engine's `Error`s stay uncaught, because a
`TypeError` turned into a tidy exit code is a broken deployment reading as a clean refusal.

The refusals are the point of the package rather than a safety net around it, so they carry a test
each: `tests/unit/DeclarationTest.phpt`.

## Things it deliberately does not do

- **No dependency injection.** A router that resolves collaborators is a container with extra steps.
- **No output formatting beyond bytes and JSON.** No tables, colours or progress bars — your
  renderers already know how your output should look.
- **No short options, no clustering, no `--opt value`.** A second name per option is a second source
  of truth.
- **No interactivity.** A prompt in something `make` calls is a hang.
- **No auto-discovery.** Nothing is found by scanning; a command exists because you declared it.

## Development

The dev dependencies are `nette/tester`, `phpstan/phpstan` and `symplify/easy-coding-standard`, all
pinned to exact versions in `composer.json` and resolved in the committed `composer.lock`, so
`composer install` is reproducible and never resolves anything fresh.

Make is the interface; the composer scripts underneath it stay usable on their own.

```
make test                # nette/tester, over tests/unit
make lint                # PHPStan, level max, over src/ and tests/fixtures
make ecs                 # coding standard, check only
make ecs-fix             # coding standard, apply
make deps-audit          # composer audit
make check               # everything above except the fixer
make on-commit           # the commit gate: check
make on-push             # the push gate: check, plus composer validate --strict
```
