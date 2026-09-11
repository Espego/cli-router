<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use LogicException;

/**
 * The router built a call the command could not accept — a bug in this package, never the user's.
 *
 * Kept distinct from UsageError so it cannot be mistaken for bad input: it exits 70 (EX_SOFTWARE)
 * and says so. If you see one, the coercer and the signature disagree.
 */
final class InternalError extends LogicException
{
}
