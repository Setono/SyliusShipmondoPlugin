<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSkip([
        __DIR__ . '/tests/Application',
        // TODO: the Playwright suite needs playwright-php/playwright-symfony, which is only installed in the CI job
        // that runs the suite, from when the plugin still supported PHP 8.1 (the package requires 8.2, as the plugin
        // now does). Process it again once the package is in require-dev (see that job in .github/workflows/build.yaml)
        __DIR__ . '/tests/Playwright',
    ])
    ->withPhpSets(php81: true)
    ->withCache(__DIR__ . '/.build/rector');
