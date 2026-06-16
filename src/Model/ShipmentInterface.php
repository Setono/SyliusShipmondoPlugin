<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Core\Model\ShipmentInterface as BaseShipmentInterface;

interface ShipmentInterface extends BaseShipmentInterface
{
    /**
     * @param array<string, mixed>|null $shipmondoPickupPoint
     */
    public function setShipmondoPickupPoint(?array $shipmondoPickupPoint): void;

    /**
     * @return array<string, mixed>|null
     */
    public function getShipmondoPickupPoint(): ?array;
}
