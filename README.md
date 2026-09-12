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

**[Worked example](docs/worked-example.md)** — a complete command set, its help snapshot, and a
canonical test pattern. Its code blocks are generated from fixtures run by the package's own test
suite. Only the resulting Markdown ships; test classes never enter a consumer's autoload surface.

## The whole entrypoint

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

(new App\Cli\Pdf\CommandSet)->run();
```

The set's constructor lists what the script needs, typed, one per parameter — ordinary constructor
injection, no container and no service locator. The base class declares no constructor at all, so a
subclass never has to call `parent::__construct()`, and a set that needs nothing stays a one-liner.
The generated worked example is the single complete command-set example; README does not keep a
second copy that could drift from it.

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

In a source checkout, Make is the interface and `make help` lists the targets; the composer scripts
underneath it stay usable on their own. `make check` is the gate both `on-commit` and `on-push` run.
