<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Doctrine\ORM\Mapping as ORM;
use Setono\Shipmondo\Response\ShipmentTemplates\ShipmentTemplate;

trait ShippingMethodTrait
{
    /**
     * @var list<int>
     *
     * @ORM\Column(type="json", nullable=true)
     */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $allowedShipmentTemplates = [];

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
