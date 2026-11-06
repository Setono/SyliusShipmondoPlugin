<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Setono\Shipmondo\Enum\WebhookAction;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\StateResolver\StateResolverInterface;
use Sylius\Component\Shipping\ShipmentTransitions;

/**
 * When Shipmondo reports that a sales order has been fully shipped, ship the Sylius shipment(s), which
 * resolves the order to "fulfilled" (when payment is complete too).
 *
 * Handling an order in Shipmondo fires `orders/create_shipment` once a shipment exists (the order
 * moves to order_status "sent" and shipped_percent 100). `status_update` is also honoured so a later
 * status change reporting full shipment is reacted to; both are gated on shipped_percent reaching 100
 * (the `status_update` that accompanies mere fulfilment still reports shipped_percent 0).
 */
final class SalesOrderShippedHandler implements OrderWebhookHandlerInterface
{
    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly StateResolverInterface $orderStateResolver,
    ) {
    }

    public static function getSubscribedActions(): array
    {
        return [WebhookAction::CreateShipment, WebhookAction::StatusUpdate];
    }

    public function handle(OrderInterface $order, RemoteEvent $webhook): void
    {
        if (!self::isFullyShipped($webhook->getPayload())) {
            return;
        }

        $shipped = false;
        foreach ($order->getShipments() as $shipment) {
            if ($this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)) {
                $this->stateMachine->apply($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP);
                $shipped = true;
            }
        }

        if ($shipped) {
            // Shipping resolves the order's shipping state; this resolves the order state to fulfilled
            $this->orderStateResolver->resolve($order);
        }
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
