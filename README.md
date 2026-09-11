# espego/cli-router

A command is a method. Its parameters are its options. Its attributes are its help.

Everything else — the accepted-option list, the usage text, the unknown-option rejection, the
`$command === null` / `fwrite(STDERR, …)` / `exit(1)` chain — is generated from the signature, so
it cannot drift from the code it describes.

One dependency: PHP 8.4.

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
        #[Arg('The file to print.', placeholder: 'file.html')] string $file,
        #[Opt('Output path. Defaults to the input name with a .pdf extension.')] ?string $out = null,
        #[Opt('Draw the letterhead and set the document title.')] ?string $title = null,
        #[Opt('Keep the heading, drop the mark.')] bool $noLogo = false,
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
#[Opt('MSA numbers.', placeholder: 'msaNr')] StringList $msa,     // --msa=IC01,IC02
#[Opt('Instance ids.', placeholder: 'id')]   ?IntList $instance = null,
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
`pattern`, a `min`/`max`, and the `separator` a list splits on.

## What a command returns

`CommandResult::json()`, `::text()` or `::nothing()`, with an exit code — plus `withNotice()` for a
line that frames what follows and `withWarning()` for one that qualifies it. Notices print to stderr
before stdout, warnings after, so ordering between the two streams is reproducible.

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

## Things it deliberately does not do

- **No dependency injection.** A router that resolves collaborators is a container with extra steps.
- **No output formatting beyond bytes and JSON.** No tables, colours or progress bars — your
  renderers already know how your output should look.
- **No short options, no clustering, no `--opt value`.** A second name per option is a second source
  of truth.
- **No interactivity.** A prompt in something `make` calls is a hang.
- **No auto-discovery.** Nothing is found by scanning; a command exists because you declared it.

## Development

The dev dependencies are `nette/tester` and `phpstan/phpstan`, both pinned to exact versions in
`composer.json`. There is no lockfile yet, so the first run has to resolve them; after that:

```
composer tester          # tests
composer phpstan         # level max, over src/
```
