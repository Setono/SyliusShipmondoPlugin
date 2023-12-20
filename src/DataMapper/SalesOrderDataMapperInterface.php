<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\Shipmondo\DTO\Model\SalesOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

interface SalesOrderDataMapperInterface
{
    /**
     * Maps an order to a Shipmondo sales order
     */
    public function map(OrderInterface $order): SalesOrder;
}
