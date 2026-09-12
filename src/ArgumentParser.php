<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/**
 * Splits argv into positional arguments and `--key=value` / `--flag` options.
 *
 * Deliberately minimal: no short flags, no clustering, no `--opt value` (space-separated), no type
 * coercion. Everything above this layer is reflection; this layer only decides what is a name,
 * what is a value, and what is neither.
 *
 * A bare `--flag` yields `true`, distinct from `--flag=` which yields `''` — the caller can then
 * refuse "given but empty" separately from "given as a flag", which is the difference between
 * writing an empty note and refusing to.
 *
 * It is also where text begins: argv arrives as bytes and leaves here as UTF-8 or not at all.
 */
final class ArgumentParser
{
    /**
     * @param list<string> $argv Arguments WITHOUT the script name.
     * @return array{args: list<string>, opts: array<string, string|true>, duplicates: list<string>}
     */
    public function parse(array $argv): array
    {
        $args = [];
        $opts = [];
        $duplicates = [];
        $endOfOptions = false;

        foreach ($argv as $position => $arg) {
            // Unix argv is bytes, and nothing above this layer is prepared for that: the help
            // counts characters, a /u pattern raises rather than matching, and the very function
            // that sanitises diagnostics used to return the empty string for them — so the whole
            // message disappeared and `error:` was all the user saw. The position, never the
            // content: echoing the bad bytes back is what is being prevented.
            if (! mb_check_encoding($arg, 'UTF-8')) {
                throw new UsageError('argument ' . ($position + 1) . ' is not valid UTF-8');
            }

            if ($endOfOptions || ! str_starts_with($arg, '--')) {
                $args[] = $arg;
                continue;
            }

            $body = substr($arg, 2);

            // A bare `--` ends option parsing. Without it there is no way at all to pass a path
            // beginning with `--`, which a file-oriented CLI eventually needs.
            if ($body === '') {
                $endOfOptions = true;
                continue;
            }

            // Only the FIRST '=' splits, so a value may itself contain one.
            $eq = strpos($body, '=');
            $name = $eq === false ? $body : substr($body, 0, $eq);

            // Reported, never resolved: silently keeping one of two occurrences is how a malformed
            // `--confirm=false` gets rescued by a later bare `--confirm`.
            if (array_key_exists($name, $opts) && ! in_array($name, $duplicates, true)) {
                $duplicates[] = $name;
            }

            $opts[$name] = $eq === false ? true : substr($body, $eq + 1);
        }

        return [
            'args' => $args,
            'opts' => $opts,
            'duplicates' => $duplicates,
        ];
    }
}
