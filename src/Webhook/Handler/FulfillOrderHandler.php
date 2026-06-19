<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Setono\Doctrine\ORMTrait;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\StateResolver\StateResolverInterface;
use Sylius\Component\Shipping\ShipmentTransitions;

/**
 * When Shipmondo reports that an order has been fully shipped/handled, ship the Sylius shipment(s),
 * which resolves the order to "fulfilled" (when payment is complete too).
 */
final class FulfillOrderHandler implements RemoteEventHandlerInterface
{
    use ORMTrait;

    public function __construct(
        private readonly OrderResolverInterface $orderResolver,
        private readonly StateMachineInterface $stateMachine,
        private readonly StateResolverInterface $orderStateResolver,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function handle(RemoteEvent $remoteEvent): void
    {
        if ('orders' !== $remoteEvent->getResource() || 'status_update' !== $remoteEvent->getAction()) {
            return;
        }

        $payload = $remoteEvent->getPayload();
        if (!self::isFullyShipped($payload)) {
            return;
        }

        $order = $this->orderResolver->resolveFromPayload($payload);
        if (null === $order) {
            return;
        }

        $applied = false;
        foreach ($order->getShipments() as $shipment) {
            if ($this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)) {
                $this->stateMachine->apply($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP);
                $applied = true;
            }
        }

        if (!$applied) {
            return;
        }

        // Shipping resolves the order's shipping state; this resolves the order state to fulfilled
        $this->orderStateResolver->resolve($order);
        $this->getManager($order)->flush();
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private static function isFullyShipped(array $payload): bool
    {
        // Shipmondo sets shipped_percent to 100 once every order line has been shipped/handled.
        if (!array_key_exists('shipped_percent', $payload)) {
            return false;
        }

        $shippedPercent = $payload['shipped_percent'];

        return is_numeric($shippedPercent) && $shippedPercent >= 100.0;
    }
}
