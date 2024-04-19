<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\Shipmondo\Client\Client;
use Setono\Shipmondo\Client\Endpoint\Endpoint;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\Shipmondo\Resolver\Shipment;
use Setono\Shipmondo\Resolver\ShipmentTemplateResolver;
use Setono\Shipmondo\Response\ShipmentTemplates\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;

final class ShipmentTemplateSalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function __construct(private readonly Client $client)
    {
    }

    public function map(OrderInterface $order, SalesOrder $salesOrder): void
    {
        $receiverCountry = $order->getShippingAddress()?->getCountryCode();
        if (null === $receiverCountry) {
            return;
        }

        $shipment = $order->getShipments()->first();
        if (false === $shipment) {
            return;
        }

        $shippingMethod = $shipment->getMethod();
        if (!$shippingMethod instanceof ShippingMethodInterface) {
            return;
        }

        $shippingMethodName = $shippingMethod->getName();
        if (null === $shippingMethodName) {
            return;
        }

        $allowedShipmentTemplates = $shippingMethod->getAllowedShipmentTemplates();

        /** @var list<ShipmentTemplate> $shipmentTemplates */
        $shipmentTemplates = [];

        foreach (Endpoint::paginate($this->client->shipmentTemplates()->get(...)) as $collection) {
            /** @var ShipmentTemplate $shipmentTemplate */
            foreach ($collection as $shipmentTemplate) {
                if (!in_array($shipmentTemplate->id, $allowedShipmentTemplates, true)) {
                    continue;
                }

                $shipmentTemplates[] = $shipmentTemplate;
            }
        }

        if ([] === $shipmentTemplates) {
            return;
        }

        $shipment = new Shipment(
            $shippingMethodName,
            'DK',
            $receiverCountry,
            self::getWeight($order),
        );

        // todo this should be a service
        $resolver = new ShipmentTemplateResolver();
        $shipmentTemplate = $resolver->resolve($shipment, $shipmentTemplates);

        $salesOrder->shipmentTemplateId = $shipmentTemplate?->id;
    }

    private static function getWeight(OrderInterface $order): int
    {
        $weight = 0;
        foreach ($order->getItems() as $item) {
            $unitWeight = $item->getVariant()?->getWeight();
            if (null === $unitWeight) {
                // todo this should be logged or even better, a notification should be sent to the store owner
                continue;
            }

            $weight += $item->getQuantity() * $unitWeight;
        }

        return (int) $weight;
    }

    private static function getShippingMethodName(OrderInterface $order): ?string
    {
        $shipment = $order->getShipments()->first();
        if (false === $shipment) {
            return null;
        }

        return $shipment->getMethod()?->getName();
    }
}
