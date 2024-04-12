<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Setono\Shipmondo\Request\SalesOrders\OrderLine;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Model\AdjustmentInterface;

/**
 * This event is dispatched when we map shipping adjustments to order lines
 */
final class ShippingOrderLineMappedEvent
{
    public function __construct(
        /**
         * This is the order line that will be uploaded to Shipmondo
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
