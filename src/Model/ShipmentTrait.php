<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Doctrine\ORM\Mapping as ORM;

trait ShipmentTrait
{
    /** @ORM\Column(type="json", nullable=true) */
    #[ORM\Column(type: 'json', nullable: true)]
    protected ?array $shipmondoPickupPoint = null;

    public function setShipmondoPickupPoint(?array $shipmondoPickupPoint): void
    {
        $this->shipmondoPickupPoint = $shipmondoPickupPoint;
    }

    public function getShipmondoPickupPoint(): ?array
    {
        return $this->shipmondoPickupPoint;
    }
}
