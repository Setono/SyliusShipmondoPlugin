<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Support;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Provider\OrderIdProviderInterface;

/**
 * A shop's own order id provider, which prefixes the order number, as a shop sharing its Shipmondo account would
 */
final class PrefixedOrderIdProvider implements OrderIdProviderInterface
{
    public function getOrderId(OrderInterface $order): string
    {
        return 'WEB-' . (string) $order->getNumber();
    }
}
