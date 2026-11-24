<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit;

use Setono\SyliusShipmondoPlugin\Failure\RecordedShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

/**
 * Builds the test app's orders for unit tests
 */
final class Orders
{
    /**
     * An order in the given Shipmondo state, linked to the given sales order if it is uploaded, and put in it by the
     * given Shipmondo failure, if any
     */
    public static function create(
        string $shipmondoState = OrderInterface::SHIPMONDO_STATE_PENDING,
        ?int $salesOrderId = null,
        string $state = OrderInterface::STATE_NEW,
        ?int $id = null,
        ?string $number = null,
        ?RecordedShipmondoFailure $failure = null,
    ): Order {
        $order = new Order();
        (new \ReflectionProperty($order, 'id'))->setValue($order, $id);
        $order->setNumber($number);
        $order->setState($state);
        $order->setShipmondoState($shipmondoState, [
            OrderWorkflow::CONTEXT_SALES_ORDER_ID => $salesOrderId,
            OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE => $failure,
        ]);

        return $order;
    }
}
