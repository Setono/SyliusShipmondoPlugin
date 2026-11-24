<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * Sends the order number as the order id
 */
final class OrderIdProvider implements OrderIdProviderInterface
{
    public function getOrderId(OrderInterface $order): string
    {
        return (string) $order->getNumber();
    }
}
