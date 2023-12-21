<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

final class MapSalesOrderEvent
{
    public function __construct(
        /**
         * This is the sales order that will be dispatched to Shipmondo
         */
        public readonly SalesOrder $salesOrder,
        /**
         * This is the order that the order that the sales order is based on
         */
        public readonly OrderInterface $order,
    ) {
    }
}
