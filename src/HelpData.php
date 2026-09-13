<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use BackedEnum;
use DateTimeInterface;

/** Builds the machine-readable counterpart of HelpRenderer's text. @internal */
final class HelpData
{
    /** @return array<string, mixed> */
    public function set(SetInfo $set): array
    {
        $commands = [];
        foreach ($set->commands as $command) {
            $commands[$command->name] = $this->command($set, $command);
        }

        return [
            'summary' => $set->meta->summary,
            'single' => $set->meta->single,
            'before' => $set->meta->before,
            'after' => $set->meta->after,
            'groups' => $set->meta->groups,
            'onEmpty' => Name::toKebab($set->meta->onEmpty->name),
            'emptyMessage' => $set->meta->emptyMessage,
            'width' => $set->meta->width,
            'dateTimeZone' => $set->dateTimeZone?->getName(),
            'globals' => array_map(
                fn (ValueSpec $spec): array => $this->value($spec, 'global'),
                $set->globals,
            ),
            'commands' => $commands,
        ];
    }

    /** @return array<string, mixed> */
    public function command(SetInfo $set, CommandInfo $command): array
    {
        return [
            'name' => $command->name,
            'summary' => $command->meta->summary,
            'description' => $command->meta->description,
            'group' => $command->meta->group,
            'hidden' => $command->meta->hidden,
            'arguments' => array_map(
                fn (ValueSpec $spec): array => $this->value($spec, 'command'),
                $command->positionals(),
            ),
            'options' => [
                ...array_map(
                    fn (ValueSpec $spec): array => $this->value($spec, 'command'),
                    $command->options(),
                ),
                ...array_map(
                    fn (ValueSpec $spec): array => $this->value($spec, 'global'),
                    $set->globalsFor($command),
                ),
            ],
        ];
    }

    /**
     * @param 'command'|'global' $source
     * @return array<string, mixed>
     */
    private function value(ValueSpec $spec, string $source): array
    {
        $list = $spec->listClass();
        $enum = $spec->enumClass();
        $element = $list === null ? null : $list::elementType();
        $elementEnum = $element !== null && ! in_array($element, ['string', 'int', 'float'], true)
            ? $element
            : null;
        $allowed = $enum ?? $elementEnum;

        return [
            'name' => $spec->cliName,
            'phpName' => $spec->phpName,
            'kind' => $spec->positional ? 'argument' : 'option',
            'source' => $source,
            'synopsis' => $spec->synopsis(),
            'description' => $spec->meta->description,
            'type' => $spec->typeName,
            'elementType' => $element,
            'allowedValues' => $allowed === null ? null : array_map(
                static fn (BackedEnum $case): string|int => $case->value,
                $allowed::cases(),
            ),
            'required' => $spec->mustBeGiven(),
            'nullable' => $spec->allowsNull,
            'variadic' => $spec->variadic,
            'hasDefault' => $spec->hasDefault,
            'default' => $spec->hasDefault ? $this->default($spec->default) : null,
            'separator' => $list === null ? null : $spec->meta->separator,
            'pattern' => $spec->meta->pattern,
            'hint' => $spec->meta->hint,
            'min' => $spec->meta->min,
            'max' => $spec->meta->max,
            'minCount' => $spec->meta->minCount,
            'maxCount' => $spec->meta->maxCount,
            'allowEmpty' => $spec->meta->allowEmpty,
            'trim' => $spec->meta->trim,
            'onlyWhen' => $spec->gate(),
        ];
    }

    private function default(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof ValueList) {
            return array_map($this->default(...), iterator_to_array($value, false));
        }

        return $value;
    }
}
