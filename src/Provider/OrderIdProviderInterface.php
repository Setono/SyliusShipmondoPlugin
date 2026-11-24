<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * The order id the plugin sends Shipmondo for an order: the sales order's `order_id`. The upload sends it, and a sales
 * order lookup searches Shipmondo by it, so both take it from here. Decorate or replace it to send another order id,
 * e.g. one with a prefix.
 *
 * A sales order lookup searches by the order id it gives now, not by the one the order's upload sent. So change what it
 * gives only while no order is unlinked: an unlinked order's lookup would find no sales order, and its order would be
 * uploaded a second time
 */
interface OrderIdProviderInterface
{
    public function getOrderId(OrderInterface $order): string;
}
