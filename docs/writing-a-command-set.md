# Writing a command set

`README.md` says what the package does and what it refuses. This says how to use it well: the order
to decide things in, and the places where the obvious reading is wrong.

Nothing here restates the reference. Where a fact lives in the code, this points at it — so paths
below are worth opening. The `tests/` ones are in the repository rather than in an installed copy:
`.gitattributes` keeps tests and fixtures out of the distribution archive.

## 1. One command, or several?

Answer this first; everything else follows from it.

- One verb, always the same thing → `#[Cli(single: true)]`. No command word on the command line.
- The caller picks a verb → a dispatching set. Then, and only then, `groups` and `hidden:` mean
  anything — `Introspector::assertNothingToList()` refuses them on a single set.
- Being invoked with no arguments at all is a normal, complete invocation (`status`, `sync`,
  `flush`) → `WhenEmpty::Run`. Otherwise pick from `src/WhenEmpty.php`, whose cases carry the
  reasoning for each.

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
  `tests/fixtures/DemoSet.php` with `tests/fixtures/Mutates.php` has it wired up end to end (a test
  fixture — it also carries deliberately pathological commands, so read it, do not copy it whole).

## 3. Push meaning into the type

Each of these moves a rule out of the body *and* into the generated help at the same time:

| instead of | declare |
|---|---|
| `string` plus a whitelist check | a backed enum — it prints its own `Allowed:` list |
| `array` plus `@param list<string>` | `StringList`, `IntList`, or an `EnumList` subclass |
| `string` plus `new DateTimeImmutable(...)` | `DateTimeImmutable` |
| an `if` in the body rejecting a number | `min:` / `max:` |

What the type genuinely cannot say goes in the attribute. The full parameter list, with what each
one is for, is the constructor docblock of `src/Param.php` — read that rather than a copy of it.

## 4. Names come from the code

`commandNoteDone()` is `note-done`; `$intendedEnv` is `--intended-env`. There is no override, so
renaming a parameter renames a flag your users type. That is an argument for §7's help snapshot,
which turns such a rename into a visible diff instead of a surprise.

## 5. Return, never print

A body returns a `CommandResult` and never calls `exit()` or writes to `STDERR` — that is what makes
it callable from a test with named arguments. The factories, the notice/warning ordering and the
exit-code gate are in `src/CommandResult.php`.

`output()` is for progress *during* a long run, not for the result. `fail()` reports bad usage from
several frames down. `stop()` exists for a decision made deep in a helper and is rare on purpose —
both are documented where they are declared, in `src/Commands.php`.

## 6. Which error to raise

| Situation | Raise | Defined in |
|---|---|---|
| The invocation is wrong — a value your code cannot accept | `$this->fail(…)` | `src/UsageError.php` |
| Something upstream said a considered *no* (a 4xx, a refusal) | your own exception, mapped with `#[CatchAs]` | `src/CatchAs.php` |
| Your set is declared wrong | nothing — the package raises it | `src/DeclarationError.php` |
| The router and a signature disagree | nothing — the package raises it | `src/InternalError.php` |

Anything else stays uncaught, deliberately: a `TypeError` turned into a tidy exit code is a broken
deployment reading as a clean refusal. Mapping `Throwable` or an `Error` is refused at
introspection, and so is mapping a `DeclarationError`.

## 7. Test it three ways

1. **The body**, called directly with named arguments — no process, no argv.
2. **The whole CLI**, via `handle()` with a `BufferedOutput`, asserting the exit code and the two
   streams separately. Both shapes are shown in `README.md`.
3. **The help, byte for byte**, against a checked-in file. Copy `tests/unit/CanonicalHelpTest.phpt`
   and `tests/unit/DemoHelp.expect`. A substring assertion is not a substitute: it passes just as
   happily when a command has silently vanished or a column has moved.

## 8. The first run is the design review

Introspection runs on every invocation, `--help` included, so a declaration that cannot work fails
immediately and names the symbol. Then read the generated help as a user would. If it reads badly,
the signature is wrong — fix the signature, not the help. There is nowhere else to fix it, which is
the point.

## 9. Traps

Each of these was measured, and each is asserted in `tests/unit/RegressionTest.phpt` under the
numbered section that found it.

- **A repeated option is an error, not last-wins.** `--confirm=false --confirm` does not mean `true`.
- **Only `--opt=value`.** No `--opt value`, no short options, no clustering.
- **`--` ends option parsing** — the only way to pass a path beginning with `--`.
- **A `bool` defaulting to `true` is refused.** It could never take another value; invert the name.
- **`pattern` is matched against the raw argument**, before it becomes a number — so a numeric
  default has to satisfy it too, and `#[Opt(pattern: '/^\d{2}$/u')] int $n = 1` is refused.
- **A declared default is validated like a typed value**, list elements included.
- **`?T $x = null` means "not given"; `string $s = ''` means "given, and empty".** They are different
  questions and `allowEmpty` only answers the second one, for strings.
- **Globals are restored after every call**, because repeated in-process `handle()` is supported.
- **Exit codes are 0–255** (`fail()`: 1–255), checked where they are constructed.
- **`width` lays out every declared paragraph**, not only the columns — a summary, `before` and
  `after` are wrapped to it, and prose that carries its own newlines keeps them.

## 10. Before you ship

- [ ] `--help`, and `help <command>` for each command, read well to someone who has not seen the code
- [ ] Every command's summary says what it does, not what it is called
- [ ] Nothing in a body validates what the declaration could have stated
- [ ] The help is snapshot-tested
- [ ] Write commands are gated — a marker attribute plus an `onlyWhen:` global, not a convention
- [ ] Exit codes mean something to the wrapper script that will read them
- [ ] `make check` is green
