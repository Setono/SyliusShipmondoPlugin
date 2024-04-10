<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Setono\Shipmondo\Request\SalesOrders\OrderLine;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;

final class MapOrderLineEvent
{
    public function __construct(
        /**
         * This is the order line that will be uploaded to Shipmondo
         */
        public readonly OrderLine $orderLine,
        /**
         * This is the order item that the order line is based on
         */
        public readonly OrderItemInterface $orderItem,
        /**
         * This is the order that the order item belongs to
         */
        public readonly OrderInterface $order,
    ) {
    }
}
