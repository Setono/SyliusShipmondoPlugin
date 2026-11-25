<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\Shipmondo\Resolver\Shipment;
use Setono\Shipmondo\Resolver\ShipmentTemplateResolver;
use Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Setono\SyliusShipmondoPlugin\Provider\SetupProviderInterface;

/**
 * Chooses the sales order's shipment template among the shipping method's allowed shipment templates that ship to the
 * shipping address's country: the one that ships from the store's country and fits the order's weight best. It warns
 * when the shipping method allows shipment templates that no longer exist in Shipmondo
 */
final readonly class ShipmentTemplateSalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function __construct(
        private SetupProviderInterface $setupProvider,
        private LoggerInterface $logger,
    ) {
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
        if ([] === $allowedShipmentTemplates) {
            return;
        }

        $staleShipmentTemplates = $this->setupProvider->staleShipmentTemplates($shippingMethod);
        if ([] !== $staleShipmentTemplates) {
            $this->logger->warning('The shipping method {shippingMethod} of the order {order} allows shipment templates that no longer exist in Shipmondo, so the order\'s shipment template is chosen among the others, if any. Remove them, or allow existing ones instead, on the admin Shipmondo page', [
                'order' => $order->getNumber(),
                'shippingMethod' => $shippingMethod->getCode(),
                'shipmentTemplates' => $staleShipmentTemplates,
            ]);
        }

        /** @var list<ShipmentTemplate> $shipmentTemplates */
        $shipmentTemplates = [];

        // the shipping method allows them, and they ship to the shipping address's country
        foreach ($this->setupProvider->shipmentTemplates() as $shipmentTemplate) {
            if (!in_array($shipmentTemplate->id, $allowedShipmentTemplates, true) || $shipmentTemplate->receiver->countryCode !== $receiverCountry) {
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
     * The country the order ships from. The admin chose the shipping method's shipment templates, so when the ones that
     * ship to the shipping address's country all ship from one country, the order ships from there, also when the store's
     * company is registered in another country. When they ship from several countries, the channel's shop billing data
     * tells which; without it, none is chosen
     *
     * @param non-empty-list<ShipmentTemplate> $shipmentTemplates the allowed ones that ship to the shipping address's country
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
