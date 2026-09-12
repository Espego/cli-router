<?php

declare(strict_types=1);

namespace Espego\CliRouter;

/**
 * What a command returns instead of printing and exiting.
 *
 * Command bodies never call exit() and never write to STDERR, which is what makes them callable
 * from a unit test with named arguments and no process involved.
 *
 * The notice/warning split is not decoration: it fixes the ORDER the two streams are written in.
 * A dry-run banner has to precede the preview it introduces; a "could not verify" warning has to
 * follow the payload it qualifies. One list either side of stdout reproduces both exactly.
 */
final class CommandResult
{
    /**
     * @param list<string> $notices stderr, before stdout
     * @param list<string> $warnings stderr, after stdout
     */
    private function __construct(
        public readonly int $exitCode,
        public readonly ?string $text,
        public readonly mixed $json,
        public readonly bool $hasJson,
        public readonly array $notices,
        public readonly array $warnings,
    ) {
        // The private constructor is the one gate every factory and wither passes through. The
        // shell reads a single byte of what it is given: 999 arrives as 231, and 256 as success.
        if ($exitCode < 0 || $exitCode > 255) {
            throw new DeclarationError("a command result exit code is 0-255, not {$exitCode}.");
        }
    }

    /** Encoded by the runner, so every script in a family formats JSON the same way. */
    public static function json(mixed $data, int $exitCode = 0): self
    {
        return new self($exitCode, null, $data, true, [], []);
    }

    /**
     * Pre-rendered bytes, emitted verbatim.
     *
     * No trailing newline is added: a renderer that already decided where its newlines go must not
     * have one appended behind its back.
     */
    public static function text(string $bytes, int $exitCode = 0): self
    {
        return new self($exitCode, $bytes, null, false, [], []);
    }

    /** An exit code and nothing on stdout. */
    public static function nothing(int $exitCode = 0): self
    {
        return new self($exitCode, null, null, false, [], []);
    }

    /** A line that frames what follows. */
    public function withNotice(string ...$lines): self
    {
        $notices = $this->notices;
        foreach ($lines as $line) {
            $notices[] = $line;
        }

        return new self($this->exitCode, $this->text, $this->json, $this->hasJson, $notices, $this->warnings);
    }

    /** A line that qualifies what was just printed. */
    public function withWarning(string ...$lines): self
    {
        $warnings = $this->warnings;
        foreach ($lines as $line) {
            $warnings[] = $line;
        }

        return new self($this->exitCode, $this->text, $this->json, $this->hasJson, $this->notices, $warnings);
    }

    public function withExitCode(int $exitCode): self
    {
        return new self($exitCode, $this->text, $this->json, $this->hasJson, $this->notices, $this->warnings);
    }
}
