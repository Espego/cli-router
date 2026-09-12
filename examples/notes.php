#!/usr/bin/env php
<?php

declare(strict_types=1);

/** The whole entry script. Everything else is generated from the signatures in NoteSet. */

require __DIR__ . '/../vendor/autoload.php';

(new Espego\CliRouter\Examples\NoteSet())->run();
