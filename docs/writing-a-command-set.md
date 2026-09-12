# Writing a command set

`README.md` says what the package is. This says how to use it well: the order to decide things in,
the design rules, what the package refuses and why, and the places where the obvious reading is
wrong.

Nothing here restates the reference. Where a fact lives in the code, this points at it — so the
`src/` paths below are worth opening; they are in the installed package. The separately shipped
`docs/worked-example.md` contains a complete set, its help snapshot, and a canonical test pattern.
Its code blocks are generated from fixtures the package tests, while those fixtures and all other
development files stay out of the distribution archive.

## 1. One command, or several?

Answer this first; everything else follows from it.

- One verb, always the same thing → `#[Cli(single: true)]`. No command word on the command line.
- The caller picks a verb → a dispatching set. Then, and only then, `groups` and `hidden:` mean
  anything. Declaring them on a single set is refused at introspection —
  *"#[Cli(single: true)] prints no command list, so groups would never be rendered"*.
- Being invoked with no arguments at all is a normal, complete invocation (`status`, `sync`,
  `flush`) → `WhenEmpty::Run`. Otherwise pick from `src/WhenEmpty.php`, whose cases carry the
  reasoning for each: `Help` (the default, exit 0), `HelpFailed` (the help, exit 1, so a typo in a
  wrapper does not look like it worked), or `Error` with an `emptyMessage`.

`Run` needs a `single: true` set whose parameters are all optional. Both halves are checked at
introspection, so it cannot be declared where it could only ever produce a usage error.

## 2. What earns a command, an argument, an option, a global

- **A command** is a verb the caller chooses between. If a flag switches which of two things the
  script does, those are two commands.
- **`#[Arg]`** is what the command is *about* — the file, the id. **`#[Opt]`** is how it behaves.
  If you would have to explain the order to someone, it is an option.
- **Required or optional is the default's job.** `string $file` is required, `?string $out = null`
  is not. There is no `required:` to set (except on a variadic — see `src/Arg.php`).
- **A global** (`#[Opt]` on a public property) is for a flag belonging to the *script* rather than
  to one command — `--raw`, `--confirm`. Gate it with `onlyWhen:` to a marker attribute rather than
  repeating the option on every write command; `src/Opt.php` documents it where it is declared, and
  `docs/worked-example.md` has it wired up end to end.

## 3. Push meaning into the type

Each of these moves a rule out of the body *and* into the generated help at the same time:

| instead of | declare |
|---|---|
| `string` plus a whitelist check | a backed enum — it prints its own `Allowed:` list |
| `array` plus `@param list<string>` | `StringList`, `IntList`, or an `EnumList` subclass |
| `string` plus `new DateTimeImmutable(...)` | `DateTimeImmutable` |
| an `if` in the body rejecting a number | `min:` / `max:` |

A multi-value option is a **type**, not an `array` with a docblock:

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

What the type genuinely cannot say goes in the attribute. The full parameter list, with what each
one is for, is the constructor docblock of `src/Param.php` — read that rather than a copy of it.

## 4. Names come from the code

`commandNoteDone()` is `note-done`; `$intendedEnv` is `--intended-env`. There is no override, so
renaming a parameter renames a flag your users type. That is an argument for §7's help snapshot,
which turns such a rename into a visible diff instead of a surprise.

## 5. Return, never print

A body returns a `CommandResult` and never calls `exit()` or writes to `STDERR` — that is what makes
it callable from a test with named arguments.

`CommandResult::json()`, `::text()` or `::nothing()`, with an exit code, plus `withNotice()` for a
line that frames what follows and `withWarning()` for one that qualifies it. Notices print to stderr
before stdout, warnings after, so ordering between the two streams is reproducible. The factories
and the exit-code gate are in `src/CommandResult.php`.

**An exit code is 0-255, and `fail()`'s is 1-255**, checked where it is constructed rather than
where it is returned. The shell reads one byte: 999 arrives as 231, 256 as success, and a `fail()`
of 0 prints a diagnostic and then reports that all is well.

`output()` is for progress *during* a long run, not for the result. `fail()` reports bad usage from
several frames down. `stop()` exists for a decision made deep in a helper and is rare on purpose —
all three are documented where they are declared, in `src/Commands.php`.

### The security boundary

`withNotice()` and `withWarning()` are terminal-safe lines: before writing them, the runner replaces
control characters, Unicode line separators and explicit bidirectional controls. The same rule
protects usage errors and mapped exception messages, so an upstream response cannot add a forged
line or a terminal escape sequence.

`CommandResult::text()` and writes through `output()` are deliberately **raw bytes**. They are the
application's renderer and progress channel, where tables, colours and multi-line output may be
intentional; never interpolate untrusted text into them without escaping it for that renderer.

Finally, CLI validation establishes a PHP value, not permission to use it anywhere. It is not shell
escaping, SQL parameterisation, path containment or authorisation. Pass subprocess arguments as an
array, parameterise database queries, constrain paths at the point of use, and perform the same
permission checks the command's underlying operation requires.

## 6. Which error to raise

| Situation | Raise | Declared in | Type raised |
|---|---|---|---|
| The invocation is wrong — a value your code cannot accept | `$this->fail(…)` | `src/Commands.php` | `UsageError` (`src/UsageError.php`) |
| Something upstream said a considered *no* (a 4xx, a refusal) | your own exception, mapped with `#[CatchAs]` | your code | yours (`src/CatchAs.php` maps it) |
| Your set is declared wrong | nothing — the package raises it | — | `DeclarationError` |
| The router and a signature disagree | nothing — the package raises it | — | `InternalError` |

Anything else stays uncaught, deliberately: a `TypeError` turned into a tidy exit code is a broken
deployment reading as a clean refusal. Mapping `Throwable` or an `Error` is refused at
introspection, and so is mapping a `DeclarationError`.

## 7. Test it three ways

The canonical test pattern in `docs/worked-example.md` demonstrates all three on one set. Treat it
as a structure to adapt: replace its bootstrap, imports, fixtures, commands and assertions with
your project's own, keeping the three levels of coverage.

1. **The body**, called directly with named arguments — no process, no argv.
2. **The whole CLI**, via `handle()` with a `BufferedOutput`, asserting the exit code and the two
   streams separately.
3. **The help, byte for byte**, against a checked-in file. The worked example includes one. A
   substring assertion is not a substitute: it passes just as happily when a command has silently
   vanished or a column has moved.

## 8. The first run is the design review

Introspection runs on every invocation, `--help` included, so a declaration that cannot work fails
immediately and names the symbol. Then read the generated help as a user would. If it reads badly,
the signature is wrong — fix the signature, not the help. There is nowhere else to fix it, which is
the point.

## 9. Traps

Each of these was measured, and each is asserted in the package's own regression suite.

- **Everything resolves before a command body exists.** Help, an unknown command, an unknown option
  and every type, pattern or range failure are answered before any middleware runs. A mistyped flag
  costs an error message and nothing else, whatever the command would have gone on to do.
- **A repeated option is an error, not last-wins.** `--confirm=false --confirm` does not mean `true`.
  Keeping the last occurrence let a malformed `--confirm=false` be rescued by a later bare
  `--confirm` and the write go ahead.
- **Only `--opt=value`.** No `--opt value`, no short options, no clustering.
- **`--` ends option parsing** — the only way to pass a path beginning with `--`.
- **A `bool` defaulting to `true` is refused.** It could never take another value; invert the name.
- **`pattern` is matched against the raw argument**, before it becomes a number — so a numeric
  default has to satisfy it too, and `#[Opt(pattern: '/^\d{2}$/u')] int $n = 1` is refused.
- **A declared default is validated like a typed value**, list elements included.
- **Not given and given empty are three cases, not two.** Measured:

  | declaration | `--x=` | omitted |
  |---|---|---|
  | `string $x = ''` | refused: *`--x is required and must have a value`* | `''` |
  | `string $x = ''`, `allowEmpty: true` | `''` | `''` — indistinguishable |
  | `?string $x = null`, `allowEmpty: true` | `''` | `null` |

  So the empty string in row one means **not** given, not "given, and empty" — without `allowEmpty`
  it is the only way `''` can arrive. `allowEmpty` alone lets the value through but does not make it
  distinguishable; only the nullable declaration answers both questions. The worked example's
  `--detail` is row three: `--detail=` removes the detail, omitting it leaves it alone.
- **`allowEmpty` is for strings only**, and is refused on a list — an empty element is dropped
  rather than kept, so it could not mean anything there.
- **Globals are restored after every call**, because repeated in-process `handle()` is supported.
- **Exit codes are 0–255** (`fail()`: 1–255), checked where they are constructed.
- **`width` lays out every declared paragraph**, not only the columns — a summary, `before` and
  `after` are wrapped to it, and prose that carries its own newlines keeps them.

## 10. What the package refuses, and why

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
faults rather than a considered no — `Throwable` and the engine's `Error`s stay uncaught.

Two more classes go the same way. A default is held to everything a typed value meets, so a list
default whose elements are not the type the list declares is refused — PHP checks only the list
class, never its contents — and so is a numeric default its own `pattern` would reject.

And metadata that cannot be reached is refused rather than left reading as configured. A global
gated on a marker no `#[Command]` carries applies to nothing. Group names are a closed set in both
directions: a `#[Cli(groups:)]` heading that is empty, repeated, or that no LISTED command fills,
and a `#[Command(group:)]` naming a heading that was never declared — the latter renders identically
to declaring no group at all, so the name does nothing. A `#[Cli(single: true)]` set prints no
command list, which makes `groups`, `group:` and `hidden:` inert on one. `emptyMessage` is printed
only by `WhenEmpty::Error`. A `width` under `HelpRenderer::MIN_WIDTH` moves nothing, because every
row is the gutter plus a minimum text column wide whatever the declaration asks for. And prose
declared as the empty string — a summary, `before`, `after`, `description` — contributes a blank
line where a sentence was promised; `#[Opt]` and `#[Arg]` descriptions are exempt, since blank is
their default and a bare `#[Opt]` is what an unattributed parameter gets.

A `DeclarationError` itself is never mapped. `#[CatchAs]` naming it is refused, and one raised at
runtime — `CommandResult::nothing(999)`, `fail('…', 0)` — is rethrown past any mapping of an
ancestor such as `LogicException`: the set is wrong, and that has to reach the first run as a fatal.

The refusals are the point of the package rather than a safety net around it, so they carry a test
each in the package's own suite.

## 11. Migrating an existing CLI

The risk in a rewrite is not that it fails — it is that it succeeds at being *tidier* while quietly
changing the contract wrapper scripts, cron entries and other people's fingers already depend on.
So capture the contract before touching the code, not after.

1. **Record what the current script does**, into files you check in: `--help` and every `help
   <command>` page; stdout for one representative invocation of each command; stderr and the exit
   code for each *significant* error input — an unknown command, an unknown option, a missing
   required value, a bad enum value, a bad number. Exit codes matter most: they are the part
   nobody notices changing until a wrapper takes the wrong branch.
2. **Write down every name that is part of the contract** — command words, option spellings, the
   order of positionals. Names come from the code here (§4), so a parameter you would have renamed
   for clarity is a breaking change. Rename deliberately or not at all.
3. **Port command by command**, keeping the old script runnable, and diff the new output against
   the recording after each one. A diff is the deliverable, not a green test.
4. **Let the declaration take over the validation last.** Move a whitelist to an enum, an `if` to
   `min:`/`max:`, an `array` to a list type (§3) — each of these changes the *message* a user sees,
   which is a contract change worth making on purpose and worth seeing in the diff.
5. **Then snapshot the new help** (§7) and delete the recordings that were only scaffolding, keeping
   the exit-code and error-message assertions.

Two things reliably differ and are worth deciding on rather than discovering: this package prints
help on stdout and errors on stderr, and it answers `--opt value` with a usage error. A script that
accepted the space-separated form has callers that use it.

## 12. Before you ship

- [ ] `--help`, and `help <command>` for each command, read well to someone who has not seen the code
- [ ] Every command's summary says what it does, not what it is called
- [ ] Nothing in a body validates what the declaration could have stated
- [ ] The help is snapshot-tested
- [ ] Write commands are gated — a marker attribute plus an `onlyWhen:` global, not a convention
- [ ] Exit codes mean something to the wrapper script that will read them
- [ ] Your project's own gate is green
