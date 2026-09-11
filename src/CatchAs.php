<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use Attribute;

/**
 * Map an exception to an exit code and one line on stderr.
 *
 * This is for a remote system's considered "no" — an upstream 4xx, a refusal — which is a result,
 * not a crash. What is NOT declared stays uncaught and keeps whatever fault handling the process
 * already has, which is the point: a fault must not be quietly turned into a tidy exit code.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class CatchAs
{
	/**
	 * @param class-string<\Throwable> $exception Matched with instanceof, so a base class catches its subclasses.
	 * @param string $format sprintf template; one %s receives getMessage().
	 */
	public function __construct(
		public string $exception,
		public int $exitCode,
		public string $format = 'error: %s',
	) {
	}
}
