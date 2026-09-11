<?php

declare(strict_types=1);

if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
	fwrite(STDERR, "vendor/ is missing. Run: composer update\n");
	exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();
