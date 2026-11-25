<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Setono\Shipmondo\Request\SalesOrder\OrderLine;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;

/**
 * This event is dispatched when we map order items to order lines
 */
final readonly class OrderLineMappedEvent
{
    public function __construct(
        /**
         * This is the order line that will be uploaded to Shipmondo
         */
        public OrderLine $orderLine,
        /**
         * This is the order item that the order line is based on
         */
        public OrderItemInterface $orderItem,
        /**
         * This is the order that the order item belongs to
         */
        public OrderInterface $order,
    ) {
    }
}
