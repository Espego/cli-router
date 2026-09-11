<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use Attribute;

/**
 * A method is a command because it carries this. Nothing is discovered by naming alone, so a
 * public helper on a command set can never become part of the CLI by accident.
 *
 * The command's name is the method name with a leading `command` stripped, kebab-cased:
 * `commandNoteDone()` is `note-done`. There is deliberately no name override — one override is
 * all it takes for the help to start describing a command that does not exist.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Command
{
    /**
     * @param string|null $description Prose printed between the synopsis and the option list.
     * @param string|null $group One of the headings declared in #[Cli(groups:)].
     * @param bool $hidden Dispatchable, but left out of the command list.
     */
    public function __construct(
        public string $summary,
        public ?string $description = null,
        public ?string $group = null,
        public bool $hidden = false,
    ) {
    }
}
