<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/**
 * The two streams, kept apart so stdout can stay machine-readable while everything a human needs
 * goes to stderr and a pipe into `jq` still works.
 */
interface Output
{
	public function out(string $bytes): void;

	public function err(string $bytes): void;
}
