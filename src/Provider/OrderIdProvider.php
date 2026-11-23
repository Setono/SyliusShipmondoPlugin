<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * The order id the plugin sends Shipmondo for an order: the sales order's `order_id`. The upload sends it, and a sales
 * order lookup searches Shipmondo by it, so both take it from here
 */
final class OrderIdProvider
{
    public function getOrderId(OrderInterface $order): string
    {
        return (string) $order->getNumber();
    }
}
