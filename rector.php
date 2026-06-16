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
    ])
    ->withPhpSets(php81: true)
    ->withCache(__DIR__ . '/.build/rector');
