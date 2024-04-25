<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Doctrine\ORM\Mapping as ORM;
use Setono\Shipmondo\Response\ShipmentTemplates\ShipmentTemplate;

trait ShippingMethodTrait
{
    /** @ORM\Column(type="boolean") */
    #[ORM\Column(type: 'boolean')]
    protected bool $pickupPointDelivery = false;

    /** @ORM\Column(type="string", nullable=true) */
    #[ORM\Column(type: 'string', nullable: true)]
    protected ?string $carrierCode = null;

    /**
     * @var list<int>
     *
     * @ORM\Column(type="json", nullable=true)
     */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $allowedShipmentTemplates = [];

    public function isPickupPointDelivery(): bool
    {
        return $this->pickupPointDelivery;
    }

    public function setPickupPointDelivery(bool $pickupPointDelivery): void
    {
        $this->pickupPointDelivery = $pickupPointDelivery;
        if (!$pickupPointDelivery) {
            $this->carrierCode = null;
        }
    }

    public function getCarrierCode(): ?string
    {
        return $this->carrierCode;
    }

    public function setCarrierCode(?string $carrierCode): void
    {
        $this->carrierCode = $carrierCode;
    }

    public function getAllowedShipmentTemplates(): array
    {
        return $this->allowedShipmentTemplates ?? [];
    }

    public function setAllowedShipmentTemplates(?array $allowedShipmentTemplates): void
    {
        $this->allowedShipmentTemplates = null;

        if (null === $allowedShipmentTemplates) {
            return;
        }

        foreach ($allowedShipmentTemplates as $allowedShipmentTemplate) {
            $this->addAllowedShipmentTemplate($allowedShipmentTemplate instanceof ShipmentTemplate ? $allowedShipmentTemplate->id : (int) $allowedShipmentTemplate);
        }
    }

    public function addAllowedShipmentTemplate(int|ShipmentTemplate $shipmentTemplate): void
    {
        if (null === $this->allowedShipmentTemplates) {
            $this->allowedShipmentTemplates = [];
        }

        $id = $shipmentTemplate instanceof ShipmentTemplate ? $shipmentTemplate->id : $shipmentTemplate;

        if ($this->hasAllowedShipmentTemplate($id)) {
            return;
        }

        $this->allowedShipmentTemplates[] = $id;
    }

    public function hasAllowedShipmentTemplate(int|ShipmentTemplate $shipmentTemplate): bool
    {
        if (null === $this->allowedShipmentTemplates) {
            return false;
        }

        return in_array($shipmentTemplate instanceof ShipmentTemplate ? $shipmentTemplate->id : $shipmentTemplate, $this->allowedShipmentTemplates, true);
    }
}
