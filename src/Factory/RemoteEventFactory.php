<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Factory;

use Setono\SyliusShipmondoPlugin\Model\RemoteEventInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

final class RemoteEventFactory implements RemoteEventFactoryInterface
{
    public function __construct(
        private readonly FactoryInterface $decoratedFactory,
    ) {
    }

    public function createNew(): RemoteEventInterface
    {
        $remoteEvent = $this->decoratedFactory->createNew();
        Assert::isInstanceOf($remoteEvent, RemoteEventInterface::class);

        return $remoteEvent;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public function createWithData(string $resource, string $action, array $payload): RemoteEventInterface
    {
        $obj = $this->createNew();
        $obj->setResource($resource);
        $obj->setAction($action);
        $obj->setPayload($payload);

        return $obj;
    }
}
