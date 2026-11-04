<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/*
 * Configuration for the Playwright suite (tests/Playwright). PHP instead of YAML because the bundle is only installed
 * where the suite runs (see the TODO in config/bundles.php), and a "playwright:" key without the bundle breaks the
 * container.
 */
return static function (ContainerConfigurator $container): void {
    if (!class_exists(Playwright\Symfony\PlaywrightSymfonyBundle::class)) {
        return;
    }

    $container->extension('playwright', [
        'base_url' => 'http://localhost',
        'intercepted_hosts' => ['localhost'],
        'assets' => [
            // served straight from public/ (the bundle assets must be copies, not symlinks: assets:install without --symlink)
            'prefixes' => ['/build', '/bundles'],
        ],
    ]);
};
