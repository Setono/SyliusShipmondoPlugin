<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Setono\Doctrine\ORMTrait;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\OrderTransitions;

/**
 * Cancels the Sylius order when the sales order is deleted in Shipmondo.
 *
 * Shipmondo has no dedicated "cancel" action for sales orders — cancelling an order archives/deletes
 * it and fires the `orders/delete` webhook (the deleted order reports `order_status: "archived"`).
 */
final class CancelOrderHandler implements RemoteEventHandlerInterface
{
    use ORMTrait;

    public function __construct(
        private readonly OrderResolverInterface $orderResolver,
        private readonly StateMachineInterface $stateMachine,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function handle(RemoteEvent $remoteEvent): void
    {
        if ('orders' !== $remoteEvent->getResource() || 'delete' !== $remoteEvent->getAction()) {
            return;
        }

        $order = $this->orderResolver->resolveFromPayload($remoteEvent->getPayload());
        if (null === $order) {
            return;
        }

        if (!$this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)) {
            return;
        }

        $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->getManager($order)->flush();
    }
}
