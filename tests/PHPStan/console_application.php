<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Tests\Setono\SyliusShipmondoPlugin\Application\Kernel;

// loads the test app's environment variables too, which the commands' services need when PHPStan reads a command's
// options and arguments from it
require __DIR__ . '/../Application/config/bootstrap.php';

$kernel = new Kernel('test', true);
$kernel->boot();

return new Application($kernel);
