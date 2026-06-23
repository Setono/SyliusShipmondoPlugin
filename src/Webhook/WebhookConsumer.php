<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook;

use Setono\SyliusShipmondoPlugin\Webhook\Handler\RemoteEventHandlerInterface;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent as BaseRemoteEvent;
use Webmozart\Assert\Assert;

final class WebhookConsumer implements ConsumerInterface
{
    public function __construct(private readonly RemoteEventHandlerInterface $remoteEventHandler)
    {
    }

    /**
     * @param BaseRemoteEvent|RemoteEvent $event
     */
    public function consume(BaseRemoteEvent $event): void
    {
        Assert::isInstanceOf($event, RemoteEvent::class);

        $this->remoteEventHandler->handle($event);
    }
}
