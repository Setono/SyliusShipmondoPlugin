<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\CompositeCompilerPass\CompositeService;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\Event\SalesOrderMappedEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * @extends CompositeService<SalesOrderDataMapperInterface>
 */
final class CompositeSalesOrderDataMapper extends CompositeService implements SalesOrderDataMapperInterface
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function map(OrderInterface $order, SalesOrder $salesOrder): void
    {
        foreach ($this->services as $service) {
            $service->map($order, $salesOrder);
        }

        $this->eventDispatcher->dispatch(new SalesOrderMappedEvent($salesOrder, $order));
    }
}
