<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use BackedEnum;
use ReflectionProperty;

/**
 * One declared value — a command's parameter or a set-wide global — reduced to what the runner
 * needs: where its name came from, what type it must end up as, and the attribute's extras.
 *
 * Parameters and globals share this shape deliberately, so the coercer has one code path and a
 * global cannot drift into behaving differently from an option of the same type.
 */
final class ValueSpec
{
    public function __construct(
        public readonly string $phpName,
        public readonly string $cliName,
        public readonly Param $meta,
        public readonly bool $positional,
        public readonly bool $variadic,
        public readonly bool $hasDefault,
        public readonly mixed $default,
        public readonly ?string $typeName,
        public readonly bool $allowsNull,
        public readonly bool $isBuiltin,
        /** Set for a set-wide global, so the runner can assign it without a dynamic property write. */
        public readonly ?ReflectionProperty $property = null,
    ) {
    }

    /**
     * A value with no default must be supplied. A variadic never must — `required: true` on #[Arg]
     * is what makes it insist, and that is a count check, not an arity one.
     */
    public function isRequired(): bool
    {
        return ! $this->hasDefault && ! $this->variadic;
    }

    /**
     * The backed enum this value must become, if it is one.
     *
     * @return class-string<BackedEnum>|null
     */
    public function enumClass(): ?string
    {
        if ($this->typeName === null || $this->isBuiltin || ! is_a($this->typeName, BackedEnum::class, true)) {
            return null;
        }

        /** @var class-string<BackedEnum> */
        return $this->typeName;
    }

    /**
     * The ValueList class this value must become, if it is one.
     *
     * @return class-string<ValueList>|null
     */
    public function listClass(): ?string
    {
        if ($this->typeName === null || $this->isBuiltin || ! is_a($this->typeName, ValueList::class, true)) {
            return null;
        }

        /** @var class-string<ValueList> */
        return $this->typeName;
    }

    public function isList(): bool
    {
        return $this->listClass() !== null;
    }

    /** What the help shows after `=`. Derived from the type unless the attribute overrides it. */
    public function placeholder(): string
    {
        if ($this->meta->placeholder !== null) {
            return $this->meta->placeholder;
        }

        $enum = $this->enumClass();
        if ($enum !== null) {
            return $this->cases($enum);
        }

        $list = $this->listClass();
        if ($list !== null) {
            $element = $list::elementType();
            if ($element !== 'string' && $element !== 'int' && $element !== 'float') {
                return $this->cases($element);
            }

            return $element === 'int' ? 'n' : ($element === 'float' ? 'x' : $this->phpName);
        }

        return match ($this->typeName) {
            'int' => 'n',
            'float' => 'x',
            default => $this->phpName,
        };
    }

    /** @param class-string<BackedEnum> $enum */
    private function cases(string $enum): string
    {
        return implode('|', array_map(static fn (BackedEnum $c): string => (string) $c->value, $enum::cases()));
    }

    /** `--x=<v>`, or bare `--x` for a flag. */
    public function synopsis(): string
    {
        if ($this->positional) {
            return $this->variadic ? '<' . $this->placeholder() . '>...' : '<' . $this->placeholder() . '>';
        }

        if ($this->typeName === 'bool') {
            return '--' . $this->cliName;
        }

        // A list says so in its synopsis, otherwise `--msa=<msaNr>` reads as accepting exactly one.
        // The repeat is spelled out only while it stays short: repeating a whole list of enum cases
        // is noise, and the reader has already seen them once.
        $value = '<' . $this->placeholder() . '>';
        if ($this->isList()) {
            $value .= mb_strlen($value) <= 14
                ? '[' . $this->meta->separator . $value . '...]'
                : '[' . $this->meta->separator . '...]';
        }

        return '--' . $this->cliName . '=' . $value;
    }
}
