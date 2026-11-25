<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * This event is dispatched after payment details has been mapped
 */
final readonly class SalesOrderMappedEvent
{
    public function __construct(
        public SalesOrderRequest $salesOrder,
        public OrderInterface $order,
    ) {
    }
}
