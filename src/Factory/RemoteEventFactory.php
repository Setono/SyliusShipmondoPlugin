<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Factory;

use Setono\SyliusShipmondoPlugin\Model\RemoteEventInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

final class RemoteEventFactory implements RemoteEventFactoryInterface
{
    public function __construct(
        /** @var FactoryInterface<RemoteEventInterface> $decoratedFactory */
        private readonly FactoryInterface $decoratedFactory,
    ) {
    }

    public function createNew(): RemoteEventInterface
    {
        return $this->decoratedFactory->createNew();
    }

    public function createWithData(string $resource, string $action, array $payload): RemoteEventInterface
    {
        $obj = $this->createNew();
        $obj->setResource($resource);
        $obj->setAction($action);
        $obj->setPayload($payload);

        return $obj;
    }
}
