<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // only FtpStorage needs it, so it is suggested rather than forced on every installation
    ->ignoreErrorsOnExtensionAndPath('ext-ftp', __DIR__ . '/src/Ftp', [ErrorType::SHADOW_DEPENDENCY]);
