<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Factory;

use Setono\SyliusShipmondoPlugin\Model\RemoteEventInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

/**
 * @extends FactoryInterface<RemoteEventInterface>
 */
interface RemoteEventFactoryInterface extends FactoryInterface
{
    public function createNew(): RemoteEventInterface;

    public function createWithData(string $resource, string $action, array $payload): RemoteEventInterface;
}
