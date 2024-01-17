<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook;

use Doctrine\Persistence\ManagerRegistry;
use Setono\DoctrineObjectManagerTrait\ORM\ORMManagerTrait;
use Setono\SyliusShipmondoPlugin\Factory\RemoteEventFactoryInterface;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent as BaseRemoteEvent;
use Webmozart\Assert\Assert;

final class WebhookConsumer implements ConsumerInterface
{
    use ORMManagerTrait;

    public function __construct(
        private readonly RemoteEventFactoryInterface $remoteEventFactory,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * @param BaseRemoteEvent|RemoteEvent $event
     */
    public function consume(BaseRemoteEvent $event): void
    {
        Assert::isInstanceOf($event, RemoteEvent::class);

        $remoteEventResource = $this->remoteEventFactory->createWithData(
            $event->getResource(),
            $event->getAction(),
            $event->getPayload(),
        );

        $manager = $this->getManager($remoteEventResource);
        $manager->persist($remoteEventResource);
        $manager->flush();
    }
}
