<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tools\UpdateDocs;

use RuntimeException;
use Throwable;

const DOCUMENT = __DIR__ . '/../docs/worked-example.md';

/** @var list<array{name: string, language: string, source: string}> */
const GENERATED_SECTIONS = [
    [
        'name' => 'note-set',
        'language' => 'php',
        'source' => __DIR__ . '/../tests/fixtures/Documentation/NoteSet.php',
    ],
    [
        'name' => 'priority',
        'language' => 'php',
        'source' => __DIR__ . '/../tests/fixtures/Documentation/Priority.php',
    ],
    [
        'name' => 'writes',
        'language' => 'php',
        'source' => __DIR__ . '/../tests/fixtures/Documentation/Writes.php',
    ],
    [
        'name' => 'rejected',
        'language' => 'php',
        'source' => __DIR__ . '/../tests/fixtures/Documentation/Rejected.php',
    ],
    [
        'name' => 'note-store',
        'language' => 'php',
        'source' => __DIR__ . '/../tests/fixtures/Documentation/NoteStore.php',
    ],
    [
        'name' => 'test',
        'language' => 'php',
        'source' => __DIR__ . '/../tests/unit/DocumentationExampleTest.phpt',
    ],
    [
        'name' => 'help',
        'language' => 'text',
        'source' => __DIR__ . '/../tests/unit/DocumentationExampleHelp.expect',
    ],
];

/** @param list<string> $arguments */
function main(array $arguments): int
{
    if ($arguments !== [] && $arguments !== ['--check']) {
        fwrite(STDERR, "usage: php tools/update-docs.php [--check]\n");

        return 2;
    }

    $document = readText(DOCUMENT);
    $updated = $document;
    foreach (GENERATED_SECTIONS as $section) {
        $updated = replaceGeneratedSection(
            $updated,
            $section['name'],
            $section['language'],
            $section['source'],
        );
    }

    if ($updated === $document) {
        return 0;
    }
    if ($arguments === ['--check']) {
        fwrite(STDERR, "docs/worked-example.md has drifted; run `make docs` and commit the result.\n");

        return 1;
    }

    writeAtomically(DOCUMENT, $updated);

    return 0;
}

function replaceGeneratedSection(string $document, string $name, string $language, string $source): string
{
    $quoted = preg_quote($name, '~');
    $pattern = "~<!-- BEGIN GENERATED: {$quoted} -->.*?<!-- END GENERATED: {$quoted} -->~su";
    $count = preg_match_all($pattern, $document);
    if ($count !== 1) {
        throw new RuntimeException("docs/worked-example.md must contain exactly one generated section named {$name}; found " . ($count === false ? 'an invalid pattern' : $count) . '.');
    }

    $sourceText = rtrim(normalizeNewlines(readText($source)), "\n");
    $replacement = "<!-- BEGIN GENERATED: {$name} -->\n```{$language}\n{$sourceText}\n```\n<!-- END GENERATED: {$name} -->";

    /** @param array<int|string, string> $_matches */
    $replace = static fn (array $_matches): string => $replacement;
    $updated = preg_replace_callback($pattern, $replace, $document);
    if ($updated === null) {
        throw new RuntimeException("could not generate documentation section {$name}.");
    }

    return $updated;
}

function readText(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("could not read {$path}.");
    }

    return $contents;
}

function normalizeNewlines(string $text): string
{
    return str_replace(["\r\n", "\r"], "\n", $text);
}

function writeAtomically(string $path, string $contents): void
{
    $temporary = tempnam(dirname($path), '.worked-example.');
    if ($temporary === false) {
        throw new RuntimeException("could not create a temporary file beside {$path}.");
    }

    try {
        if (file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
            throw new RuntimeException("could not write {$path}.");
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

try {
    $rawArguments = $_SERVER['argv'] ?? [];
    $arguments = [];
    if (is_array($rawArguments)) {
        foreach ($rawArguments as $argument) {
            if (is_string($argument)) {
                $arguments[] = $argument;
            }
        }
    }

    exit(main(array_slice($arguments, 1)));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
