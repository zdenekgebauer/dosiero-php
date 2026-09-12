<?php

declare(strict_types=1);

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
    // levels start at 0, this code has never been through Rector
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withSkip(
        [
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
