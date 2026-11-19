<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToExclude(__DIR__ . '/tests')
    ->ignoreUnknownClasses(['SensitiveParameter'])
    // used through config: the extension prepends framework scoped HTTP clients, which FrameworkBundle only
    // registers when symfony/http-client is installed, and the Shipmondo clients send through their PSR-18 versions
    ->ignoreErrorsOnPackage('symfony/http-client', [ErrorType::UNUSED_DEPENDENCY])
;
