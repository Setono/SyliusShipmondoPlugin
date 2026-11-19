<?php

declare(strict_types=1);

use Symfony\Component\Console\Application;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Tests\Setono\SyliusShipmondoPlugin\Application\Kernel;
use Webmozart\Assert\Assert;

// loads the test app's environment variables too, which the commands' services need when PHPStan reads a command's
// options and arguments from it
require __DIR__ . '/../Application/config/bootstrap.php';

$kernel = new Kernel('test', true);
$kernel->boot();

// Only the plugin's commands, the ones PHPStan analyses: it builds every command of the application it is given, and
// some of the test app's commands can't be built in PHPStan, e.g. translation:extract with the lowest dependencies,
// whose symfony/translation (< 6.4.3) calls nikic/php-parser 4's API, while PHPStan bundles php-parser 5
$commandLoader = $kernel->getContainer()->get('console.command_loader');
Assert::isInstanceOf($commandLoader, CommandLoaderInterface::class);

$application = new Application();
foreach ($commandLoader->getNames() as $name) {
    if (str_starts_with($name, 'setono:sylius-shipmondo:')) {
        $application->add($commandLoader->get($name));
    }
}

return $application;
