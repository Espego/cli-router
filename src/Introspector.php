<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use Attribute;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;

/**
 * Reads a command set's declarations. The whole "single source of truth" claim lives here: nothing
 * below this class knows a command name or an option name that was not taken from a PHP symbol.
 *
 * Every mistake it can detect, it throws on — a command method without #[Command], a name that
 * cannot become a flag, a global colliding with a parameter, a constraint that could never apply.
 * A declaration error must fail on the first run, loudly, rather than turn into help text that
 * lies or a limit that reads as enforced and is not.
 *
 * @internal Not part of the public surface; may change in any release.
 */
final class Introspector
{
    /**
     * Names the runner answers itself, so a declaration using one could never be reached.
     *
     * @var list<string>
     */
    private const RESERVED = ['help'];

    /**
     * Exceptions the runner handles before #[CatchAs] is consulted, so mapping one is dead code.
     *
     * @var list<class-string<Throwable>>
     */
    private const HANDLED = [UsageError::class, Stop::class, InternalError::class];

    public function set(object $set): SetInfo
    {
        $class = new ReflectionClass($set);

        $cli = $this->attribute($class->getAttributes(Cli::class), $class->getName());
        if ($cli === null) {
            throw new DeclarationError($class->getName() . ' is missing #[Cli]. A command set declares its own summary.');
        }

        $globals = $this->globals($class);
        $commands = $this->commands($class, $globals);

        if ($commands === []) {
            throw new DeclarationError($class->getName() . ' declares no #[Command] method.');
        }
        if ($cli->single && count($commands) > 1) {
            throw new DeclarationError(sprintf(
                '%s is #[Cli(single: true)] but declares %d commands: %s. A single set holds exactly one.',
                $class->getName(),
                count($commands),
                implode(', ', array_keys($commands)),
            ));
        }

        return new SetInfo($cli, $commands, $globals, $this->catches($class));
    }

    /**
     * @param ReflectionClass<object> $class
     * @return list<CatchAs>
     */
    private function catches(ReflectionClass $class): array
    {
        $catches = [];
        $where = $class->getName();

        foreach ($class->getAttributes(CatchAs::class) as $attribute) {
            $catch = $attribute->newInstance();

            if (! is_a($catch->exception, Throwable::class, true)) {
                throw new DeclarationError(sprintf(
                    '%s: #[CatchAs] names %s, which is not a Throwable.',
                    $where,
                    $catch->exception,
                ));
            }
            foreach (self::HANDLED as $handled) {
                if (is_a($catch->exception, $handled, true)) {
                    throw new DeclarationError(sprintf(
                        '%s: #[CatchAs] names %s, which the runner handles itself. The mapping could never fire.',
                        $where,
                        $catch->exception,
                    ));
                }
            }
            foreach ($catches as $earlier) {
                if (is_a($catch->exception, $earlier->exception, true)) {
                    throw new DeclarationError(sprintf(
                        '%s: #[CatchAs] names %s after %s, which already catches it. The later mapping could never fire.',
                        $where,
                        $catch->exception,
                        $earlier->exception,
                    ));
                }
            }

            // The exit code reaches the shell, which reads one byte of it: 0 would report a caught
            // exception as success, and 999 arrives as 231.
            if ($catch->exitCode < 1 || $catch->exitCode > 255) {
                throw new DeclarationError(sprintf(
                    '%s: #[CatchAs(%s)] exitCode %d is outside 1-255.',
                    $where,
                    $catch->exception,
                    $catch->exitCode,
                ));
            }
            $this->assertFormat($catch, $where);

            $catches[] = $catch;
        }

        return $catches;
    }

    /**
     * The format is handed to sprintf() with exactly one argument, so a second placeholder throws
     * an ArgumentCountError from inside the catch block — the one place a fault must not appear.
     */
    private function assertFormat(CatchAs $catch, string $where): void
    {
        if (str_contains($catch->format, "\n")) {
            throw new DeclarationError(sprintf(
                '%s: #[CatchAs(%s)] format spans several lines. A mapped exception reports one.',
                $where,
                $catch->exception,
            ));
        }

        $placeholders = substr_count(str_replace('%%', '', $catch->format), '%');
        if ($placeholders !== 1 || ! str_contains(str_replace('%%', '', $catch->format), '%s')) {
            throw new DeclarationError(sprintf(
                "%s: #[CatchAs(%s)] format '%s' must carry exactly one %%s.",
                $where,
                $catch->exception,
                $catch->format,
            ));
        }
    }

    /**
     * @param ReflectionClass<object> $class
     * @param list<ValueSpec> $globals
     * @return array<string, CommandInfo>
     */
    private function commands(ReflectionClass $class, array $globals): array
    {
        /** @var list<string> $globalNames */
        $globalNames = array_column($globals, 'cliName');
        $commands = [];

        // Every method, not only the public ones: a #[Command] the runner could never call has to
        // say so, and silence would read as "my command vanished".
        foreach ($class->getMethods() as $method) {
            $where = $class->getName() . '::' . $method->getName() . '()';
            $meta = $this->attribute($method->getAttributes(Command::class), $where);

            if ($meta === null) {
                if (str_starts_with($method->getName(), 'command')) {
                    throw new DeclarationError(sprintf(
                        '%s::%s() is named like a command but carries no #[Command].',
                        $class->getName(),
                        $method->getName(),
                    ));
                }
                continue;
            }

            if (! $method->isPublic()) {
                throw new DeclarationError("{$where}: a #[Command] must be public.");
            }
            if ($method->isStatic()) {
                throw new DeclarationError("{$where}: a #[Command] cannot be static — it is called on the set.");
            }
            if (! Name::isValid($method->getName())) {
                throw new DeclarationError(
                    "{$where}: '{$method->getName()}' cannot become a command name. Use plain camelCase."
                );
            }
            $this->assertReturnsResult($method, $where);

            $name = Name::ofCommand($method->getName());

            if (in_array($name, self::RESERVED, true)) {
                throw new DeclarationError("{$where}: '{$name}' is a reserved command name.");
            }
            if (isset($commands[$name])) {
                throw new DeclarationError(sprintf(
                    "%s: '%s' is declared twice — %s() and %s() both normalise to it.",
                    $class->getName(),
                    $name,
                    $commands[$name]->method->getName(),
                    $method->getName(),
                ));
            }

            $params = $this->params($method, $globalNames);

            $commands[$name] = new CommandInfo($name, $method, $meta, $params);
        }

        return $commands;
    }

    /**
     * Without this the mistake surfaces as `internal error: … must return a CommandResult` and an
     * exit code of 70, which blames the router for the consumer's signature.
     */
    private function assertReturnsResult(ReflectionMethod $method, string $where): void
    {
        $return = $method->getReturnType();
        $named = $return instanceof ReflectionNamedType ? $return : null;

        if ($named === null || $named->allowsNull() || $named->getName() !== CommandResult::class) {
            throw new DeclarationError(sprintf(
                '%s: a #[Command] must return CommandResult, not %s.',
                $where,
                $return === null ? 'nothing declared' : (string) $return,
            ));
        }
    }

    /**
     * @param list<string> $globalNames
     * @return list<ValueSpec>
     */
    private function params(ReflectionMethod $method, array $globalNames): array
    {
        $specs = [];
        $seenVariadic = false;

        foreach ($method->getParameters() as $parameter) {
            $where = sprintf(
                '%s::%s($%s)',
                $method->getDeclaringClass()->getName(),
                $method->getName(),
                $parameter->getName()
            );

            $meta = $this->attribute(
                $parameter->getAttributes(Param::class, ReflectionAttribute::IS_INSTANCEOF),
                $where,
            ) ?? new Opt();

            if (! Name::isValid($parameter->getName())) {
                throw new DeclarationError(
                    "{$where}: '{$parameter->getName()}' cannot become a command-line name. Use plain camelCase."
                );
            }

            $cliName = Name::toKebab($parameter->getName());
            $positional = $meta instanceof Arg;

            if (! $positional && in_array($cliName, self::RESERVED, true)) {
                throw new DeclarationError("{$where}: --{$cliName} is reserved by the runner.");
            }

            if (! $positional && in_array($cliName, $globalNames, true)) {
                throw new DeclarationError("{$where}: --{$cliName} is already a global of this set.");
            }
            if ($parameter->isVariadic() && ! $positional) {
                throw new DeclarationError("{$where}: a variadic parameter must be #[Arg], not an option.");
            }
            if ($seenVariadic) {
                throw new DeclarationError("{$where}: nothing may follow a variadic parameter.");
            }
            $seenVariadic = $parameter->isVariadic();

            $specs[] = $this->spec($parameter, $meta, $cliName, $positional, $where);
        }

        return $specs;
    }

    private function spec(
        ReflectionParameter $parameter,
        Param $meta,
        string $cliName,
        bool $positional,
        string $where,
    ): ValueSpec {
        if ($parameter->isPassedByReference()) {
            throw new DeclarationError("{$where}: a command parameter cannot be by-reference.");
        }

        $type = $parameter->getType();
        $named = $type instanceof ReflectionNamedType ? $type : null;
        $this->assertSupported($named, $type === null, $meta, $where);

        $spec = new ValueSpec(
            phpName: $parameter->getName(),
            cliName: $cliName,
            meta: $meta,
            positional: $positional,
            variadic: $parameter->isVariadic(),
            hasDefault: $parameter->isDefaultValueAvailable(),
            default: $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            typeName: $named?->getName(),
            allowsNull: $named?->allowsNull() ?? true,
            isBuiltin: $named?->isBuiltin() ?? true,
        );
        $this->assertConstraints($spec, $where);

        return $spec;
    }

    /**
     * A value the coercer can actually produce.
     *
     * Without this a union, an unknown class or a bare `array` fell through to the string branch
     * and quietly became something the signature never asked for — the declaration and the runtime
     * disagreeing, which is the one failure this package exists to prevent.
     */
    private function assertSupported(?ReflectionNamedType $named, bool $untyped, Param $meta, string $where): void
    {
        if ($meta->separator === '') {
            throw new DeclarationError("{$where}: the separator cannot be empty.");
        }
        if ($meta->pattern !== null) {
            $this->assertPattern($meta->pattern, $where);
        }
        if ($untyped || $named === null) {
            throw new DeclarationError("{$where}: needs a type. Union and intersection types are not supported.");
        }

        $name = $named->getName();
        if ($named->isBuiltin()) {
            if (! in_array($name, ['string', 'int', 'float', 'bool'], true)) {
                throw new DeclarationError(
                    "{$where}: '{$name}' is not a supported option type. For several values, use a ValueList."
                );
            }

            return;
        }

        if (is_a($name, BackedEnum::class, true)) {
            $this->assertEnum($name, $where);

            return;
        }
        if (is_a($name, ValueList::class, true)) {
            $this->assertList($name, $where);

            return;
        }
        if ($name === DateTimeImmutable::class) {
            return;
        }
        if (is_a($name, DateTimeInterface::class, true)) {
            throw new DeclarationError(
                "{$where}: use DateTimeImmutable — a mutable date handed to a command can be changed under it."
            );
        }

        throw new DeclarationError("{$where}: '{$name}' is not a supported option type.");
    }

    /**
     * Compile the pattern to find out whether it is one, and insist on /u.
     *
     * Arguments arrive as UTF-8 and the help counts characters, so a pattern that reasons in bytes
     * disagrees with everything around it — `.` would match half a Czech letter.
     */
    private function assertPattern(string $pattern, string $where): void
    {
        $problem = null;
        set_error_handler(static function (int $severity, string $message) use (&$problem): bool {
            $problem = $message;

            return true;
        });

        try {
            $compiled = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        if ($compiled === false) {
            throw new DeclarationError(sprintf(
                '%s: pattern is not a valid regular expression%s',
                $where,
                $problem === null ? '.' : ': ' . str_replace('preg_match(): ', '', $problem) . '.',
            ));
        }
        if (! str_contains($this->modifiers($pattern), 'u')) {
            throw new DeclarationError("{$where}: pattern needs the u modifier — values arrive as UTF-8.");
        }
    }

    /** Whatever follows the closing delimiter of a pattern that has already been compiled. */
    private function modifiers(string $pattern): string
    {
        $delimiter = $pattern[0];
        $closing = match ($delimiter) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            '<' => '>',
            default => $delimiter,
        };
        $end = strrpos($pattern, $closing);

        return $end === false ? '' : substr($pattern, $end + 1);
    }

    /** @param class-string $enum */
    private function assertEnum(string $enum, string $where): void
    {
        if (! enum_exists($enum)) {
            throw new DeclarationError("{$where}: '{$enum}' is not an enum.");
        }
        if ($enum::cases() === []) {
            throw new DeclarationError("{$where}: '{$enum}' declares no cases, so no value could ever be accepted.");
        }
    }

    /**
     * A ValueList is trusted for its element type all the way into the help, where an unusable one
     * used to surface as `Class "bool" not found` while rendering — a fatal, from a declaration.
     *
     * @param class-string<ValueList> $list
     */
    private function assertList(string $list, string $where): void
    {
        // Abstract or the interface itself, not merely non-instantiable: a private constructor
        // behind the documented of() factory is how a ValueList is meant to be built.
        $class = new ReflectionClass($list);
        if ($class->isAbstract() || $class->isInterface()) {
            throw new DeclarationError("{$where}: '{$list}' is abstract. Name a concrete ValueList.");
        }

        $element = $list::elementType();
        if (in_array($element, ['string', 'int', 'float'], true)) {
            return;
        }
        if (! is_a($element, BackedEnum::class, true)) {
            throw new DeclarationError(sprintf(
                "%s: %s::elementType() returns '%s'. It must be 'string', 'int', 'float' or a backed enum.",
                $where,
                $list,
                $element,
            ));
        }

        $this->assertEnum($element, $where);
    }

    /**
     * A constraint that cannot apply is worse than no constraint: the declaration reads as enforced
     * and the coercer never looks at it. Each one is refused where it could not take effect, and
     * the declared default is put through the same predicates a typed value will meet.
     */
    private function assertConstraints(ValueSpec $spec, string $where): void
    {
        $meta = $spec->meta;
        $element = $spec->isList() ? $this->element($spec) : $spec->typeName;

        if ($spec->positional && $spec->typeName === 'bool') {
            throw new DeclarationError("{$where}: a positional cannot be bool — there is no way to supply one.");
        }
        if ($meta instanceof Arg && $meta->required && ! $spec->variadic) {
            throw new DeclarationError(
                "{$where}: required applies to a variadic. A positional is required unless it has a default."
            );
        }
        if ($meta instanceof Opt && $meta->onlyWhen !== null) {
            if ($spec->property === null) {
                throw new DeclarationError("{$where}: onlyWhen applies to a global, not to a command parameter.");
            }
            $this->assertMarker($meta->onlyWhen, $where);
        }

        if ($meta->pattern !== null && ($element === 'bool' || $this->isEnum($element))) {
            throw new DeclarationError("{$where}: pattern cannot apply to {$element} — its accepted values are its type.");
        }
        if ($meta->allowEmpty && $spec->isList()) {
            throw new DeclarationError("{$where}: allowEmpty cannot apply to a list — an empty element is dropped.");
        }

        $this->assertCounts($spec, $where);
        $this->assertBounds($spec, $element, $where);
        $this->assertDefault($spec, $where);
    }

    private function assertCounts(ValueSpec $spec, string $where): void
    {
        $counted = $spec->isList() || $spec->variadic;

        foreach ([
            'minCount' => $spec->meta->minCount,
            'maxCount' => $spec->meta->maxCount,
        ] as $label => $count) {
            if ($count === null) {
                continue;
            }
            if (! $counted) {
                throw new DeclarationError("{$where}: {$label} applies to a list or a variadic, which this is not.");
            }
            if ($count < 1) {
                throw new DeclarationError("{$where}: {$label} is {$count}; it must be at least 1.");
            }
        }

        if ($spec->meta->minCount !== null
            && $spec->meta->maxCount !== null
            && $spec->meta->minCount > $spec->meta->maxCount
        ) {
            throw new DeclarationError(sprintf(
                '%s: minCount %d is above maxCount %d, so nothing could satisfy both.',
                $where,
                $spec->meta->minCount,
                $spec->meta->maxCount,
            ));
        }
    }

    private function assertBounds(ValueSpec $spec, ?string $element, string $where): void
    {
        $meta = $spec->meta;
        if ($meta->min === null && $meta->max === null) {
            return;
        }

        if ($element !== 'int' && $element !== 'float') {
            throw new DeclarationError(
                "{$where}: min and max apply to int and float. The coercer never checks them here."
            );
        }
        if ($meta->min !== null && $meta->max !== null && $meta->min > $meta->max) {
            throw new DeclarationError(sprintf(
                '%s: min %s is above max %s, so no value could satisfy both.',
                $where,
                (string) $meta->min,
                (string) $meta->max,
            ));
        }
    }

    /**
     * The default never passes through the coercer — it is handed to the command as declared — so
     * it is the one value a constraint cannot otherwise reach. A default outside its own min/max
     * is the quietest way for a declaration to be wrong: nothing is typed, so nothing is rejected.
     *
     * null and '' are exempt because they are how a signature spells "not given": `string $s = ''`
     * is an ordinary optional option, and holding its emptiness against a pattern meant for real
     * values would refuse most of the declarations this package exists to serve.
     */
    private function assertDefault(ValueSpec $spec, string $where): void
    {
        $meta = $spec->meta;
        $default = $spec->default;

        if (! $spec->hasDefault || $default === null || $default === '') {
            return;
        }

        if (is_int($default) || is_float($default)) {
            if (! Constraints::atLeast($default, $meta->min) || ! Constraints::atMost($default, $meta->max)) {
                throw new DeclarationError(sprintf(
                    '%s: the default %s is outside the min/max the same declaration sets.',
                    $where,
                    (string) $default,
                ));
            }
        }

        if (is_string($default) && ! Constraints::matchesPattern($meta->pattern, $default)) {
            throw new DeclarationError("{$where}: the default '{$default}' does not match its own pattern.");
        }

        if ($default instanceof ValueList) {
            $count = count($default);
            if (! Constraints::atLeast($count, $meta->minCount) || ! Constraints::atMost($count, $meta->maxCount)) {
                throw new DeclarationError(sprintf(
                    '%s: the default holds %d elements, outside the minCount/maxCount the same declaration sets.',
                    $where,
                    $count,
                ));
            }
        }
    }

    /**
     * onlyWhen is asked of a command with getAttributes(), so a class that is not an attribute
     * targeting methods can only ever answer no — and the global would silently apply to nothing.
     */
    private function assertMarker(string $marker, string $where): void
    {
        if (! class_exists($marker)) {
            throw new DeclarationError("{$where}: onlyWhen names '{$marker}', which is not a class.");
        }

        $attribute = $this->attribute((new ReflectionClass($marker))->getAttributes(Attribute::class), $marker);
        if ($attribute === null) {
            throw new DeclarationError("{$where}: onlyWhen names '{$marker}', which is not an attribute class.");
        }
        if (($attribute->flags & Attribute::TARGET_METHOD) === 0) {
            throw new DeclarationError("{$where}: onlyWhen names '{$marker}', which cannot target a method.");
        }
    }

    private function element(ValueSpec $spec): ?string
    {
        $list = $spec->listClass();

        return $list === null ? null : $list::elementType();
    }

    /** No autoload: assertSupported() has already resolved any class named by a declaration. */
    private function isEnum(?string $name): bool
    {
        return $name !== null && enum_exists($name, false);
    }

    /**
     * Set-wide options, declared as public properties carrying #[Opt].
     *
     * A property rather than a repeated parameter, because these are the options that genuinely
     * belong to the script rather than to one command, and repeating them on fifteen signatures
     * would be the duplication this package exists to remove.
     *
     * @param ReflectionClass<object> $class
     * @return list<ValueSpec>
     */
    private function globals(ReflectionClass $class): array
    {
        $globals = [];

        foreach ($class->getProperties() as $property) {
            $where = $class->getName() . '::$' . $property->getName();
            $meta = $this->attribute($property->getAttributes(Opt::class), $where);
            if ($meta === null) {
                continue;
            }

            // Declared but unreachable is worse than not declared: the help would advertise an
            // option the runner could never assign.
            if (! $property->isPublic()) {
                throw new DeclarationError("{$where}: a global #[Opt] must be public.");
            }
            if ($property->isStatic()) {
                throw new DeclarationError("{$where}: a global #[Opt] cannot be static.");
            }
            if ($property->isReadOnly()) {
                throw new DeclarationError("{$where}: a global #[Opt] cannot be readonly — the runner assigns it.");
            }

            if (! Name::isValid($property->getName())) {
                throw new DeclarationError("{$where}: cannot become a command-line name. Use plain camelCase.");
            }
            $cliName = Name::toKebab($property->getName());
            if (in_array($cliName, self::RESERVED, true)) {
                throw new DeclarationError("{$where}: that name is reserved by the runner.");
            }
            if (! $property->hasDefaultValue()) {
                throw new DeclarationError(
                    "{$where}: a global #[Opt] needs a default — it is what applies when the flag is absent."
                );
            }

            $type = $property->getType();
            $named = $type instanceof ReflectionNamedType ? $type : null;
            $this->assertSupported($named, $type === null, $meta, $where);

            $global = new ValueSpec(
                phpName: $property->getName(),
                cliName: $cliName,
                meta: $meta,
                positional: false,
                variadic: false,
                hasDefault: true,
                default: $property->getDefaultValue(),
                typeName: $named?->getName(),
                allowsNull: $named?->allowsNull() ?? true,
                isBuiltin: $named?->isBuiltin() ?? true,
                property: $property,
            );
            $this->assertConstraints($global, $where);

            $globals[] = $global;
        }

        return $globals;
    }

    /**
     * The one instance of an attribute, or null.
     *
     * Several is refused rather than resolved: taking the first silently picked a winner between
     * #[Arg] and #[Opt] on one parameter, and the help then described the loser.
     *
     * @template T of object
     * @param array<ReflectionAttribute<T>> $attributes
     * @return T|null
     */
    private function attribute(array $attributes, string $where): ?object
    {
        if ($attributes === []) {
            return null;
        }
        if (count($attributes) > 1) {
            $names = array_values(array_unique(array_map(
                static fn (ReflectionAttribute $a): string => '#[' . (new ReflectionClass($a->getName()))->getShortName() . ']',
                $attributes,
            )));

            throw new DeclarationError(sprintf(
                '%s: carries %s. One of them decides, and it must be the only one.',
                $where,
                count($names) === 1 ? $names[0] . ' more than once' : implode(' and ', $names),
            ));
        }

        return $attributes[0]->newInstance();
    }
}
