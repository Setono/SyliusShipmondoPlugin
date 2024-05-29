<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * This event is dispatched after payment details has been mapped
 */
final class SalesOrderMappedEvent
{
    public function __construct(
        public readonly SalesOrder $salesOrder,
        public readonly OrderInterface $order,
    ) {
    }
}
