<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use BackedEnum;

/**
 * Renders the help from the declarations. Nothing here is written by hand in a consumer, which is
 * the point: a command list that is generated cannot list four of five, and an option marked
 * required in prose cannot be optional in the signature.
 *
 * Every width is counted in CHARACTERS, never bytes. A byte-counted indent puts a line carrying a
 * tick or a diacritic in the wrong column, and the text wraps early by however many multi-byte
 * characters it happens to contain.
 *
 * @internal Not part of the public surface; may change in any release.
 */
final class HelpRenderer
{
    private const OPTION_GUTTER = 25;

    private const MIN_COMMAND_GUTTER = 24;

    private const MAX_COMMAND_GUTTER = 40;

    public function render(SetInfo $set, ?string $topic, string $program): string
    {
        return $topic === null
            ? $this->set($set, $program)
            : $this->command($set, $set->get($topic), $program);
    }

    private function set(SetInfo $set, string $program): string
    {
        $out = $set->meta->summary . "\n\n";
        $out .= $this->usage($set, null, $program) . "\n";

        if ($set->meta->before !== null) {
            $out .= "\n" . $set->meta->before . "\n";
        }

        if (! $set->meta->single) {
            $out .= $this->commandList($set);
        }

        if ($set->meta->single) {
            $out .= $this->argumentList($set->only(), $set->meta->width);
            $options = [...$set->only()->options(), ...$set->globalsFor($set->only())];
        } else {
            // Only the globals that apply everywhere. One gated by a marker attribute applies to
            // some commands and not others, so listing it here states something untrue of most of
            // them; it belongs in the help of the commands that actually take it.
            $options = array_values(array_filter(
                $set->globals,
                static fn (ValueSpec $g): bool => ! $g->meta instanceof Opt || $g->meta->onlyWhen === null,
            ));
        }

        $out .= "\n" . $this->optionList($options, $set->meta->width);

        if ($set->meta->after !== null) {
            $out .= "\n" . $set->meta->after . "\n";
        }

        return $out;
    }

    private function command(SetInfo $set, CommandInfo $command, string $program): string
    {
        $out = $set->meta->summary . "\n\n";
        $out .= $this->usage($set, $command, $program) . "\n";
        $out .= "\n" . $command->meta->summary . "\n";

        if ($command->meta->description !== null) {
            $out .= "\n" . $this->paragraph($command->meta->description, $set->meta->width) . "\n";
        }

        $out .= $this->argumentList($command, $set->meta->width);
        $out .= "\n" . $this->optionList(
            [...$command->options(), ...$set->globalsFor($command)],
            $set->meta->width,
        );

        return $out;
    }

    private function usage(SetInfo $set, ?CommandInfo $command, string $program): string
    {
        if ($command !== null) {
            $head = "  {$program} {$command->name} ";

            return $this->hanging($head, $this->synopsis($command), $set->meta->width);
        }

        if ($set->meta->single) {
            return $this->hanging("  {$program} ", $this->synopsis($set->only()), $set->meta->width);
        }

        return "  {$program} <command> [options]\n  {$program} help [<command>]";
    }

    /** @return list<string> */
    private function synopsis(CommandInfo $command): array
    {
        $parts = [];

        foreach ($command->params as $spec) {
            $part = $spec->synopsis();
            $parts[] = $spec->mustBeGiven() ? $part : "[{$part}]";
        }

        return $parts;
    }

    private function commandList(SetInfo $set): string
    {
        $visible = array_filter($set->commands, static fn (CommandInfo $c): bool => ! $c->meta->hidden);
        if ($visible === []) {
            return '';
        }

        $labels = [];
        foreach ($visible as $command) {
            $labels[$command->name] = trim($command->name . ' ' . implode(' ', $this->synopsis($command)));
        }

        $gutter = $this->gutter($labels);
        $groups = $set->meta->groups === [] ? [null] : $set->meta->groups;
        $out = '';

        foreach ($groups as $group) {
            $members = array_filter(
                $visible,
                static fn (CommandInfo $c): bool => $group === null || $c->meta->group === $group,
            );
            if ($members === []) {
                continue;
            }

            $out .= "\n" . ($group === null ? 'Commands:' : $group . ':') . "\n";
            foreach ($members as $command) {
                $out .= $this->commandRow($command, $gutter, $set->meta->width);
            }
        }

        // A command whose group is not declared would otherwise vanish from the help entirely.
        $orphans = array_filter($visible, static fn (CommandInfo $c): bool => $set->meta->groups !== []
            && ! in_array($c->meta->group, $set->meta->groups, true));
        if ($orphans !== []) {
            $out .= "\nOther commands:\n";
            foreach ($orphans as $command) {
                $out .= $this->commandRow($command, $gutter, $set->meta->width);
            }
        }

        return $out;
    }

    /**
     * One command in the list: its synopsis, then its summary at the gutter.
     *
     * A synopsis too long for the gutter wraps under the command name and pushes the summary onto
     * its own lines, so one verbose command cannot move every other row's column.
     */
    private function commandRow(CommandInfo $command, int $gutter, int $width): string
    {
        $parts = $this->synopsis($command);
        $oneLine = trim($command->name . ' ' . implode(' ', $parts));

        if (mb_strlen($oneLine) + 2 <= $gutter - 1) {
            return $this->row($oneLine, $command->meta->summary, $gutter, $width);
        }

        $head = '  ' . $command->name . ' ';
        $synopsis = $parts === [] ? '  ' . $command->name : $this->hanging($head, $parts, $width);

        $out = $synopsis . "\n";
        foreach ($this->wrap($command->meta->summary, max(20, $width - $gutter)) as $line) {
            $out .= str_repeat(' ', $gutter) . $line . "\n";
        }

        return $out;
    }

    /** Positionals carry descriptions too, and a synopsis line has nowhere to put them. */
    private function argumentList(CommandInfo $command, int $width): string
    {
        $positionals = $command->positionals();
        if ($positionals === []) {
            return '';
        }

        $out = "\nArguments:\n";
        foreach ($positionals as $spec) {
            $out .= $this->row($spec->synopsis(), $this->optionText($spec), self::OPTION_GUTTER, $width);
        }

        return $out;
    }

    /** @param list<ValueSpec> $options */
    private function optionList(array $options, int $width): string
    {
        $out = "Options:\n";
        $rows = [];

        foreach ($options as $spec) {
            $rows[$spec->synopsis()] = $this->optionText($spec);
        }
        $rows['--help'] = 'Print this.';

        foreach ($rows as $label => $text) {
            $out .= $this->row((string) $label, $text, self::OPTION_GUTTER, $width);
        }

        return $out;
    }

    /** Required-ness, the enum's cases and the default are stated from the signature, never typed. */
    private function optionText(ValueSpec $spec): string
    {
        $text = $spec->meta->description;

        $enum = $spec->enumClass();
        if ($enum !== null && $spec->meta->placeholder !== null) {
            $text = rtrim($text) . ' One of: ' . implode(', ', array_map(
                static fn (BackedEnum $c): string => (string) $c->value,
                $enum::cases(),
            )) . '.';
        }

        if ($spec->isRequired()) {
            return rtrim($text) . ' Required.';
        }

        if ($spec->typeName !== 'bool' && $spec->hasDefault && $spec->default !== null) {
            $default = $spec->default;
            $shown = $default instanceof BackedEnum ? (string) $default->value : $default;
            if (is_scalar($shown)) {
                $shown = (string) $shown;
                $text = rtrim($text) . ' Default: ' . ($shown === '' ? 'empty' : $shown) . '.';
            }
        }

        return $text;
    }

    /** @param array<string, string> $labels */
    private function gutter(array $labels): int
    {
        $longest = 0;
        foreach ($labels as $label) {
            $length = mb_strlen($label) + 4;
            if ($length <= self::MAX_COMMAND_GUTTER && $length > $longest) {
                $longest = $length;
            }
        }

        return max(self::MIN_COMMAND_GUTTER, $longest);
    }

    /**
     * A label and its text, side by side when the label fits and stacked when it does not — so one
     * long synopsis cannot push every other line's column out.
     */
    private function row(string $label, string $text, int $gutter, int $width): string
    {
        $indent = str_repeat(' ', $gutter);
        $lines = $this->wrap($text, max(20, $width - $gutter));

        if (mb_strlen($label) + 2 > $gutter - 1) {
            $out = '  ' . $label . "\n";
            foreach ($lines as $line) {
                $out .= $indent . $line . "\n";
            }

            return $out;
        }

        $first = '  ' . $label . str_repeat(' ', $gutter - 2 - mb_strlen($label));
        $out = rtrim($first . (array_shift($lines) ?? '')) . "\n";
        foreach ($lines as $line) {
            $out .= $indent . $line . "\n";
        }

        return $out;
    }

    /**
     * A head followed by parts, wrapped so continuations line up under the first part.
     *
     * @param list<string> $parts
     */
    private function hanging(string $head, array $parts, int $width): string
    {
        $indent = str_repeat(' ', mb_strlen($head));
        $line = $head;
        $out = '';
        $first = true;

        foreach ($parts as $part) {
            if (! $first && mb_strlen($line) + 1 + mb_strlen($part) > $width) {
                $out .= rtrim($line) . "\n";
                $line = $indent;
            }
            $line .= ($first || $line === $indent ? '' : ' ') . $part;
            $first = false;
        }

        return $out . rtrim($line);
    }

    private function paragraph(string $text, int $width): string
    {
        return implode("\n", $this->wrap($text, $width));
    }

    /** @return list<string> */
    private function wrap(string $text, int $width): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $paragraph) {
            $line = '';
            $words = preg_split('/\s+/u', trim($paragraph));
            foreach ($words === false ? [] : $words as $word) {
                if ($word === '') {
                    continue;
                }
                if ($line !== '' && mb_strlen($line) + 1 + mb_strlen($word) > $width) {
                    $lines[] = $line;
                    $line = $word;
                    continue;
                }
                $line .= ($line === '' ? '' : ' ') . $word;
            }
            $lines[] = $line;
        }

        return $lines;
    }
}
