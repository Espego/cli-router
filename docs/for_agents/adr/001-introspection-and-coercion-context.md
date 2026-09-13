# ADR 001: Introspection exposes the CLI contract and owns coercion context

## Decision

The command set remains the single declaration source. `commandNames()`, `helpPages()`,
`helpData()`, text help and JSON help all consume the same `SetInfo`; none parses rendered output.

Required `ValueList` parameters declare whether zero elements are allowed. `DateTimeImmutable`
parameters obtain a `DateTimeZone` from the set. Middleware remains after complete validation and
does not initialise state needed by coercion.

Traits are supported as PHP composition. Introspection identifies imported methods by source file
and line so it can reject a trait command silently shadowed by its using class while accepting a
normal import or an `as` alias.

## Consequences

- Human and machine help cannot disagree about names, types, enum cases or applicable globals.
- Empty list filters and timezone-less process state cannot silently broaden or shift an operation.
- Adding a required list or date parameter requires an explicit policy in the command set.
- Agent-only architecture notes remain excluded from release archives.
