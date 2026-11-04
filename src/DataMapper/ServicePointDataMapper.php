<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\Shipmondo\Request\SalesOrder\ServicePoint;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;

/**
 * Makes the pickup point the customer chose the sales order's service point
 */
final class ServicePointDataMapper implements SalesOrderDataMapperInterface
{
    public function map(OrderInterface $order, SalesOrderRequest $salesOrder): void
    {
        $shipment = $order->getShipments()->first();
        if (!$shipment instanceof ShipmentInterface) {
            return;
        }

        $pickupPoint = $shipment->getShipmondoPickupPoint();
        if (null === $pickupPoint) {
            return;
        }

        $salesOrder->servicePoint = ServicePoint::fromPickupPoint($pickupPoint);
    }
}
