<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Tests\Setono\SyliusShipmondoPlugin\Application\Kernel;

require __DIR__ . '/../../vendor/autoload.php';

$kernel = new Kernel('test', true);
$kernel->boot();

return new Application($kernel);
