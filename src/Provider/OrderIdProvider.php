<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * Sends the order number as the order id, after the order id prefix (setono_sylius_shipmondo.order_id_prefix, none by
 * default)
 */
final readonly class OrderIdProvider implements OrderIdProviderInterface
{
    public function __construct(private string $prefix = '')
    {
    }

    public function getOrderId(OrderInterface $order): string
    {
        return $this->prefix . $order->getNumber();
    }
}
