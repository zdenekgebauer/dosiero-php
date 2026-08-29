<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\ClassMethod\NewlineBeforeNewAssignSetRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths(
        [
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ]
    )
    ->withPhpSets(php83: true)
    ->withPHPStanConfigs([__DIR__ . '/build/phpstan.neon'])
    // this code has never been through Rector - raise the levels one step at a
    // time and commit in between, otherwise the first run buries everything in
    // one unreviewable diff
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withSkip(
        [
            NewlineAfterStatementRector::class,
            NewlineBeforeNewAssignSetRector::class,
            __DIR__ . '/tests/_support/_generated',
            __DIR__ . '/tests/Support/_generated',
            __DIR__ . '/vendor',
        ]
    )
    ->withImportNames(
        importShortClasses: false,
        removeUnusedImports: true,
    )
    ->withParallel()
    ->withCache(
        cacheDirectory: __DIR__ . '/build/cache/rector',
    )
    ->withRealPathReporting();
