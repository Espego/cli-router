<?php

declare(strict_types=1);

namespace Espego\CliRouter\Tools\CheckDistribution;

use RuntimeException;
use Throwable;

/**
 * Verify the Git archive consumed as the committed release candidate. The check lives in on-push
 * rather than on-commit because git archive intentionally reads HEAD, not uncommitted working-tree
 * files.
 */
function main(): int
{
    $root = dirname(__DIR__);
    $archive = tempnam(sys_get_temp_dir(), 'cli-router-dist-');
    if ($archive === false) {
        throw new RuntimeException('could not create a temporary archive path.');
    }

    try {
        /** @var list<string> $ignored */
        $ignored = [];
        exec(
            'git -C ' . escapeshellarg($root) . ' archive --format=tar --output=' . escapeshellarg($archive) . ' HEAD',
            $ignored,
            $archiveStatus,
        );
        if ($archiveStatus !== 0) {
            throw new RuntimeException('git archive HEAD failed.');
        }

        /** @var list<string> $listed */
        $listed = [];
        exec('tar -tf ' . escapeshellarg($archive), $listed, $tarStatus);
        if ($tarStatus !== 0) {
            throw new RuntimeException('could not list the release archive.');
        }

        $files = array_values(array_filter(
            $listed,
            static fn (string $path): bool => $path !== '' && ! str_ends_with($path, '/'),
        ));
        sort($files);

        $unexpected = array_values(array_filter(
            $files,
            static fn (string $path): bool => ! isAllowed($path),
        ));
        $required = [
            'README.md',
            'composer.json',
            'docs/worked-example.md',
            'docs/writing-a-command-set.md',
        ];
        $missing = array_values(array_diff($required, $files));
        $hasSource = array_any($files, static fn (string $path): bool => str_starts_with($path, 'src/'));

        if ($unexpected !== [] || $missing !== [] || ! $hasSource) {
            foreach ($unexpected as $path) {
                fwrite(STDERR, "unexpected release file: {$path}\n");
            }
            foreach ($missing as $path) {
                fwrite(STDERR, "missing release file: {$path}\n");
            }
            if (! $hasSource) {
                fwrite(STDERR, "missing release files under src/.\n");
            }

            return 1;
        }

        fwrite(STDOUT, "Release archive contains only runtime code and Markdown documentation.\n");

        return 0;
    } finally {
        unlink($archive);
    }
}

function isAllowed(string $path): bool
{
    return $path === 'README.md'
        || $path === 'composer.json'
        || (str_starts_with($path, 'docs/')
            && ! str_starts_with($path, 'docs/for_agents/')
            && str_ends_with($path, '.md'))
        || (str_starts_with($path, 'src/') && str_ends_with($path, '.php'));
}

try {
    exit(main());
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
