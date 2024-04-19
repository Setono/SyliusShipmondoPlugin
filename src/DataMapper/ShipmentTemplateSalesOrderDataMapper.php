<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\Shipmondo\Client\Client;
use Setono\Shipmondo\Client\Endpoint\Endpoint;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\Shipmondo\Resolver\Shipment;
use Setono\Shipmondo\Resolver\ShipmentTemplateResolver;
use Setono\Shipmondo\Resolver\SimilarTextBasedShipmentsResemblanceChecker;
use Setono\Shipmondo\Response\ShipmentTemplates\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

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

        $shippingMethodName = self::getShippingMethodName($order);
        if (null === $shippingMethodName) {
            return;
        }

        /** @var list<ShipmentTemplate> $shipmentTemplates */
        $shipmentTemplates = [];

        foreach (Endpoint::paginate($this->client->shipmentTemplates()->get(...)) as $collection) {
            /** @var ShipmentTemplate $shipmentTemplate */
            foreach ($collection as $shipmentTemplate) {
                $shipmentTemplates[] = $shipmentTemplate;
            }
        }

        $shipment = new Shipment(
            $shippingMethodName,
            'DK',
            $receiverCountry,
            self::getWeight($order),
        );

        $resolver = new ShipmentTemplateResolver(new SimilarTextBasedShipmentsResemblanceChecker(10));
        $shipmentTemplate = $resolver->resolve($shipment, $shipmentTemplates);

        $salesOrder->shipmentTemplateId = $shipmentTemplate?->id;
    }

    private static function getWeight(OrderInterface $order): int
    {
        $weight = 0;
        foreach ($order->getItems() as $item) {
            $unitWeight = $item->getVariant()?->getWeight();
            if (null === $unitWeight) {
                continue;
            }

            $weight += $item->getQuantity() * $unitWeight;
        }

        return (int) $weight;
    }

    private static function getShippingMethodName(OrderInterface $order): ?string
    {
        $shipment = $order->getshipments()->first();
        if (false === $shipment) {
            return null;
        }

        return $shipment->getMethod()?->getName();
    }
}
