<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlerInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;

/**
 * Records what it was handed in a log it shares with other spies, so a test can tell which handlers ran, and in which
 * order. A test subclasses it anonymously to choose the actions it subscribes to.
 */
abstract class OrderWebhookHandlerSpy implements OrderWebhookHandlerInterface
{
    /**
     * @param \ArrayObject<int, array{string, OrderInterface, RemoteEvent}> $log
     */
    public function __construct(private readonly string $name, private readonly \ArrayObject $log)
    {
    }

    public function handle(OrderInterface $order, RemoteEvent $event): void
    {
        $this->log->append([$this->name, $order, $event]);
    }
}
