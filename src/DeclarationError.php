<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use LogicException;

/**
 * A declaration that cannot work, refused before anything runs.
 *
 * Kept distinct from InternalError so the two cannot be confused: this one names a symbol in the
 * consumer's command set and is always the consumer's to fix, where an InternalError says the
 * router and a signature disagree and is always ours. Nothing catches it — a declaration error
 * must reach the first run as a fatal, not become help text that lies.
 */
final class DeclarationError extends LogicException
{
}
