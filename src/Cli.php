<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use Attribute;

/**
 * The script-level declaration: everything a hand-written help heredoc carried that the method
 * signatures cannot say.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Cli
{
	/**
	 * @param string|null $usage Synopsis block. Null generates one from the script name.
	 * @param string|null $before Prose between the synopsis and the command list.
	 * @param string|null $after Prose after the option list.
	 * @param bool $single No subcommand word — the set holds exactly one command.
	 * @param list<string> $groups Group headings, in print order. A command names one via #[Command(group:)].
	 * @param string|null $emptyMessage Used only with WhenEmpty::Error.
	 * @param int $width Wrap column, counted in characters.
	 */
	public function __construct(
		public string $summary,
		public ?string $usage = null,
		public ?string $before = null,
		public ?string $after = null,
		public bool $single = false,
		/** @var list<string> */
		public array $groups = [],
		public WhenEmpty $onEmpty = WhenEmpty::Help,
		public ?string $emptyMessage = null,
		public int $width = 92,
	) {
	}
}
