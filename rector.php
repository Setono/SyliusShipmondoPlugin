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
        // TODO: the Playwright suite needs playwright-php/playwright-symfony, which requires PHP >= 8.2 while the plugin
        // still supports 8.1, so it is only installed in the CI job that runs the suite. Process it again once the
        // package is in require-dev (see that job in .github/workflows/build.yaml).
        __DIR__ . '/tests/Playwright',
    ])
    ->withPhpSets(php81: true)
    ->withCache(__DIR__ . '/.build/rector');
