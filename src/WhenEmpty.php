<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/**
 * What a script does when it is invoked with no arguments at all.
 *
 * The three cases are not hypothetical: they are what the scripts this package was extracted from
 * already did, each hand-rolled. Asking for the help is a success; being called with nothing is
 * sometimes a success and sometimes a mistake, and only the script knows which.
 */
enum WhenEmpty
{
    /** Print the help on stdout, exit 0. */
    case Help;

    /** Print the help on stdout, exit 1 — a typo in a wrapper script must not look like it worked. */
    case HelpFailed;

    /** One line on stderr, exit 1. */
    case Error;
}
