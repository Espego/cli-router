<?php

// vendor/bin/ecs --fix

declare(strict_types=1);

use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPreparedSets(common: true, psr12: true)
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/examples',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        Symplify\CodingStandard\Fixer\Spacing\MethodChainingNewlineFixer::class,
        PhpCsFixer\Fixer\ControlStructure\NoUselessElseFixer::class,
        PhpCsFixer\Fixer\Phpdoc\PhpdocLineSpanFixer::class,
        OrderedClassElementsFixer::class,
    ])
;
