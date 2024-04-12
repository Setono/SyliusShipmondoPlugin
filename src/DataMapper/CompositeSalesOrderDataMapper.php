<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\CompositeCompilerPass\CompositeService;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * @extends CompositeService<SalesOrderDataMapperInterface>
 */
final class CompositeSalesOrderDataMapper extends CompositeService implements SalesOrderDataMapperInterface
{
    public function map(OrderInterface $order, SalesOrder $salesOrder): void
    {
        foreach ($this->services as $service) {
            $service->map($order, $salesOrder);
        }
    }
}
