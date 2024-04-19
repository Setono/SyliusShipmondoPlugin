<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Setono\Shipmondo\Response\ShipmentTemplates\ShipmentTemplate;
use Sylius\Component\Core\Model\ShippingMethodInterface as BaseShippingMethodInterface;

interface ShippingMethodInterface extends BaseShippingMethodInterface
{
    /**
     * Returns true if shipments with this shipping method are delivered to a pickup point
     */
    public function isPickupPointDelivery(): bool;

    public function setPickupPointDelivery(bool $pickupPointDelivery): void;

    /**
     * Returns a list Shipmondo shipment template ids that are allowed for this shipping method
     *
     * @return list<int>
     */
    public function getAllowedShipmentTemplates(): array;

    /**
     * @param list<string|int|ShipmentTemplate> $allowedShipmentTemplates
     */
    public function setAllowedShipmentTemplates(?array $allowedShipmentTemplates): void;

    public function addAllowedShipmentTemplate(int|ShipmentTemplate $shipmentTemplate): void;

    public function hasAllowedShipmentTemplate(int|ShipmentTemplate $shipmentTemplate): bool;
}
