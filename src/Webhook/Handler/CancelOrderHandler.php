<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Doctrine\Persistence\ObjectManager;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\OrderTransitions;

/**
 * Cancels the Sylius order when the order is cancelled/deleted in Shipmondo.
 */
final class CancelOrderHandler implements RemoteEventHandlerInterface
{
    /**
     * The `order_status` value Shipmondo reports for a cancelled order.
     *
     * @todo confirm against a real webhook payload (see UPGRADE/verification)
     */
    private const SHIPMONDO_ORDER_STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly OrderResolverInterface $orderResolver,
        private readonly StateMachineInterface $stateMachine,
        private readonly ObjectManager $orderManager,
    ) {
    }

    public function handle(RemoteEvent $remoteEvent): void
    {
        if ('orders' !== $remoteEvent->getResource() || !self::isCancellation($remoteEvent)) {
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
        $this->orderManager->flush();
    }

    private static function isCancellation(RemoteEvent $remoteEvent): bool
    {
        if ('delete' === $remoteEvent->getAction()) {
            return true;
        }

        if ('status_update' === $remoteEvent->getAction()) {
            return self::SHIPMONDO_ORDER_STATUS_CANCELLED === ($remoteEvent->getPayload()['order_status'] ?? '');
        }

        return false;
    }
}
