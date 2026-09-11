<?php

declare(strict_types=1);

/** A real entry script, invoked as a subprocess so run() itself is exercised. */

require __DIR__ . '/../../vendor/autoload.php';

(new Espego\CliRouter\Tests\FileSet())->run();
