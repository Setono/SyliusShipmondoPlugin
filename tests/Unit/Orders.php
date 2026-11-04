<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

/**
 * Builds the test app's orders for unit tests
 */
final class Orders
{
    /**
     * An order in the given Shipmondo state, linked to the given sales order if it is uploaded
     */
    public static function create(
        string $shipmondoState = OrderInterface::SHIPMONDO_STATE_PENDING,
        ?int $salesOrderId = null,
        string $state = OrderInterface::STATE_NEW,
        ?int $id = null,
        ?string $number = null,
    ): Order {
        $order = new Order();
        (new \ReflectionProperty($order, 'id'))->setValue($order, $id);
        $order->setNumber($number);
        $order->setState($state);
        $order->setShipmondoState($shipmondoState, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => $salesOrderId]);

        return $order;
    }
}
