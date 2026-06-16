<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // The test application (host Sylius app, compiled container cache, generated assets) is not part of the
    // plugin's source and references many bundles only relevant to the test harness.
    ->addPathToExclude(__DIR__ . '/tests/Application')

    // #[\SensitiveParameter] is a native PHP 8.2 attribute. On the supported PHP 8.1 baseline the class does not
    // exist, so the analyser cannot autoload it. Run the analysis on PHP 8.1 (see the CI workflow).
    ->ignoreUnknownClasses(['SensitiveParameter'])

    // sylius/sylius is the dev-only metapackage that "replace"s the individual Sylius components the plugin requires.
    // Because it is installed in dev, classes from the required split packages resolve to it instead, so the
    // SyliusPluginTrait (actually shipped by the required sylius/core-bundle) looks like a dev dependency.
    ->ignoreErrorsOnPackage('sylius/sylius', [ErrorType::DEV_DEPENDENCY_IN_PROD])

    // ...and for the same reason the required split packages look unused (their classes resolve to sylius/sylius).
    // sylius/ui-bundle is additionally only wired through configuration/Twig, never imported as a PHP class.
    ->ignoreErrorsOnPackages([
        'sylius/core',
        'sylius/core-bundle',
        'sylius/order',
        'sylius/shipping-bundle',
        'sylius/ui-bundle',
    ], [ErrorType::UNUSED_DEPENDENCY]);
