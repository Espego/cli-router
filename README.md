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

## Start here

**[Writing a command set](docs/writing-a-command-set.md)** — the order to decide things in, what
each declaration is for, which error to raise, what to snapshot, what the package refuses and why,
and how to move an existing CLI onto it without changing the contract its callers read.

`examples/` is that guide's worked example: a complete command set, its help snapshot, and a test
you can copy. It ships with the package and the package's own test suite runs it, so it cannot go
stale against the code.

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

The set's constructor lists what the script needs, typed, one per parameter — ordinary constructor
injection, no container and no service locator. The base class declares no constructor at all, so a
subclass never has to call `parent::__construct()`, and a set that needs nothing stays a one-liner.

## What you declare

| | |
|---|---|
| `#[Cli]` | on the class — the script's summary, synopsis, group headings, footer prose, what to do with no arguments |
| `#[Command]` | on a method — it is a command because it carries this, never because of its name |
| `#[Opt]` | on a parameter (an option) or a public property (a set-wide global) |
| `#[Arg]` | on a parameter — a positional; a variadic takes the rest |
| `#[CatchAs]` | on the class, repeatable — map an exception to an exit code and one stderr line |

Names come from the code: `commandNoteDone()` is `note-done`, `$intendedEnv` is `--intended-env`.
There is no name override, deliberately — one override is all it takes for the help to start
describing a flag that does not exist.

Types come from the signature: `string` is required unless it has a default, `bool` is a flag that
refuses a value, `?DateTimeImmutable` parses, a backed enum validates itself and prints its own
`Allowed:` list, and a `ValueList` subtype is a multi-value option. The attribute carries only what
a type genuinely cannot say — a description, a `pattern`, a `min`/`max`, a `minCount`/`maxCount`, a
`separator`. Every parameter is documented in the constructor docblock of `src/Param.php`.

## What the parser does with argv

- `--key=value` and a bare `--flag`. No short options, no clustering, no `--opt value`.
- A repeated option is an error, never resolved to the last occurrence.
- `--` ends option parsing; everything after it is positional whatever it looks like.
- An argument that is not valid UTF-8 is refused by position. argv is text or it is nothing.

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

Make is the interface and `make help` lists the targets; the composer scripts underneath it stay
usable on their own. `make check` is the gate both `on-commit` and `on-push` run.
