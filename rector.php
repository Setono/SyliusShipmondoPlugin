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
        // TODO: process it once playwright-php/playwright-symfony is in require-dev
        __DIR__ . '/tests/Playwright',
    ])
    ->withPhpSets(php82: true)
    ->withCache(__DIR__ . '/.build/rector');
