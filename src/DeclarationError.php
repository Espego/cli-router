<?php

declare(strict_types=1);

namespace Espego\CliRouter;

use LogicException;

/**
 * Something the consumer stated that cannot work — an attribute refused at introspection, or a
 * value handed to the router that it could not honour, such as an exit code the shell cannot carry.
 *
 * Kept distinct from InternalError so the two cannot be confused: this one names a symbol in the
 * consumer's command set and is always the consumer's to fix, where an InternalError says the
 * router and a signature disagree and is always ours. Nothing catches it — it must reach the first
 * run as a fatal, not become help text that lies or an exit code that means something else.
 */
final class DeclarationError extends LogicException
{
}
