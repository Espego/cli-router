<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/**
 * Turns untrusted text into one inert terminal line.
 *
 * Replacing rather than deleting preserves where something unsafe appeared. The explicit bidi
 * controls are listed instead of rejecting every Unicode format character: joiners and similar
 * characters are legitimate text in some languages, while these ones can reorder a diagnostic.
 */
final class Diagnostic
{
    private const UNSAFE = '/[\p{Cc}\p{Zl}\p{Zp}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    private const LINE_BREAK = '/[\r\n\x{0085}\x{2028}\x{2029}]/u';

    /** Sanitise untrusted text before including it in one application-authored diagnostic line. */
    public static function line(string $text): string
    {
        // argv is checked earlier, but an exception or upstream response can still carry malformed
        // bytes. Make the input valid before asking a Unicode regexp to inspect it.
        $text = mb_scrub($text, 'UTF-8');

        return preg_replace(self::UNSAFE, '?', $text)
            ?? throw new InternalError('could not sanitise a diagnostic');
    }

    /** @internal Does text contain any Unicode or ASCII character that renders as a new line? */
    public static function spansLines(string $text): bool
    {
        return preg_match(self::LINE_BREAK, mb_scrub($text, 'UTF-8')) === 1;
    }
}
