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
use Symfony\Contracts\Service\ResetInterface;

/**
 * Chooses the sales order's shipment template among the shipping method's allowed shipment templates: the one that ships
 * from the store's country to the shipping address's country and fits the order's weight best
 */
final class ShipmentTemplateSalesOrderDataMapper implements SalesOrderDataMapperInterface, ResetInterface
{
    /**
     * Every shipment template in Shipmondo, fetched once until the mapper is reset: once per upload run, and once per
     * request or message, since the container resets services tagged kernel.reset between them
     *
     * @var list<ShipmentTemplate>|null
     */
    private ?array $shipmentTemplates = null;

    public function __construct(private readonly Client $client)
    {
    }

    public function reset(): void
    {
        $this->shipmentTemplates = null;
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

        foreach ($this->shipmentTemplates() as $shipmentTemplate) {
            if (!in_array($shipmentTemplate->id, $allowedShipmentTemplates, true)) {
                continue;
            }

            $shipmentTemplates[] = $shipmentTemplate;
        }

        if ([] === $shipmentTemplates) {
            return;
        }

        $senderCountry = self::getSenderCountry($order, $shipmentTemplates);
        if (null === $senderCountry) {
            return;
        }

        $shipment = new Shipment(
            $shippingMethodName,
            $senderCountry,
            $receiverCountry,
            self::getWeight($order),
        );

        // todo this should be a service
        $resolver = new ShipmentTemplateResolver();
        $shipmentTemplate = $resolver->resolve($shipment, $shipmentTemplates);

        $salesOrder->shipmentTemplateId = $shipmentTemplate?->id;
    }

    /**
     * @return list<ShipmentTemplate>
     */
    private function shipmentTemplates(): array
    {
        if (null === $this->shipmentTemplates) {
            $shipmentTemplates = [];
            foreach ($this->client->shipmentTemplates()->paginate() as $shipmentTemplate) {
                $shipmentTemplates[] = $shipmentTemplate;
            }

            $this->shipmentTemplates = $shipmentTemplates;
        }

        return $this->shipmentTemplates;
    }

    /**
     * The country the order ships from. The admin chose the shipping method's shipment templates, so when they all ship
     * from one country, the order ships from there, also when the store's company is registered in another country. When
     * they ship from several countries, the channel's shop billing data tells which; without it, none is chosen
     *
     * @param non-empty-list<ShipmentTemplate> $shipmentTemplates
     */
    private static function getSenderCountry(OrderInterface $order, array $shipmentTemplates): ?string
    {
        $senderCountry = $shipmentTemplates[0]->sender->countryCode;

        foreach ($shipmentTemplates as $shipmentTemplate) {
            if ($shipmentTemplate->sender->countryCode !== $senderCountry) {
                return $order->getChannel()?->getShopBillingData()?->getCountryCode();
            }
        }

        return $senderCountry;
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
