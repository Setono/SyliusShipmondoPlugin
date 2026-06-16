<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Factory;

use Setono\SyliusShipmondoPlugin\Model\RemoteEventInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

interface RemoteEventFactoryInterface extends FactoryInterface
{
    public function createNew(): RemoteEventInterface;

    /**
     * @param array<array-key, mixed> $payload
     */
    public function createWithData(string $resource, string $action, array $payload): RemoteEventInterface;
}
