<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Support;

/**
 * Sales orders as Shipmondo lists them, for the tests of a sales order lookup
 */
final class SalesOrders
{
    /**
     * Shipmondo's list of these sales orders, by their id and order id
     *
     * @param array<int, string|null> $orderIds
     *
     * @return list<array{id: int, order_id: string|null, source_name: string}>
     */
    public static function withOrderIds(array $orderIds): array
    {
        $salesOrders = [];
        foreach ($orderIds as $id => $orderId) {
            $salesOrders[] = ['id' => $id, 'order_id' => $orderId, 'source_name' => 'Sylius'];
        }

        return $salesOrders;
    }
}
