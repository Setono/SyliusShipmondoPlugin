<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\Shipmondo\Request\SalesOrders\ServicePoint;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;

/**
 * Will map the pickup point data to the sales order
 */
final class ServicePointDataMapper implements SalesOrderDataMapperInterface
{
    public function map(OrderInterface $order, SalesOrder $salesOrder): void
    {
        $shipment = $order->getShipments()->first();
        if (!$shipment instanceof ShipmentInterface) {
            return;
        }

        /**
         * todo make the shipmondo pickup point a value object that's mapped in Doctrine
         *
         * The keys below match the properties from this class: \Setono\Shipmondo\Response\PickupPoints\PickupPoint
         *
         * @var array{id: string, name: string, address: string, zipcode: string, city: string, country: string, address2: string|null}|null $pickupPoint
         */
        $pickupPoint = $shipment->getShipmondoPickupPoint();
        if (null === $pickupPoint) {
            return;
        }

        $salesOrder->servicePoint = new ServicePoint(
            id: $pickupPoint['id'],
            name: $pickupPoint['name'],
            address1: $pickupPoint['address'],
            zipCode: $pickupPoint['zipcode'],
            city: $pickupPoint['city'],
            countryCode: $pickupPoint['country'],
            address2: $pickupPoint['address2'] ?? null,
        );
    }
}
