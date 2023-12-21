<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Setono\Shipmondo\Request\SalesOrders\OrderLine;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Model\AdjustmentInterface;

final class MapShippingOrderLineEvent
{
    public function __construct(
        /**
         * This is the order line that will be dispatched to Shipmondo
         */
        public readonly OrderLine $orderLine,
        /**
         * This is the shipping adjustment that the order line is based on
         */
        public readonly AdjustmentInterface $adjustment,
        /**
         * This is the order that the order item belongs to
         */
        public readonly OrderInterface $order,
    ) {
    }
}
