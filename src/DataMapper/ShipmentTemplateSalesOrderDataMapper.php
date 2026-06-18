<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\Shipmondo\Client\Client;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\Shipmondo\Resolver\Shipment;
use Setono\Shipmondo\Resolver\ShipmentTemplateResolver;
use Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;

final class ShipmentTemplateSalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function __construct(private readonly Client $client)
    {
    }

    public function map(OrderInterface $order, SalesOrderRequest $salesOrder): void
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

        foreach ($this->client->shipmentTemplates()->paginate() as $shipmentTemplate) {
            if (!in_array($shipmentTemplate->id, $allowedShipmentTemplates, true)) {
                continue;
            }

            $shipmentTemplates[] = $shipmentTemplate;
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
}
