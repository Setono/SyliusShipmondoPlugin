<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Doctrine\ORM\Mapping as ORM;
use Setono\Shipmondo\Response\PickupPoint\PickupPoint;
use Setono\SyliusShipmondoPlugin\Doctrine\Type\PickupPointType;

trait ShipmentTrait
{
    /** @ORM\Column(type="setono_sylius_shipmondo_pickup_point", nullable=true) */
    #[ORM\Column(type: PickupPointType::NAME, nullable: true)]
    protected ?PickupPoint $shipmondoPickupPoint = null;

    public function setShipmondoPickupPoint(?PickupPoint $shipmondoPickupPoint): void
    {
        $this->shipmondoPickupPoint = $shipmondoPickupPoint;
    }

    public function getShipmondoPickupPoint(): ?PickupPoint
    {
        return $this->shipmondoPickupPoint;
    }
}
