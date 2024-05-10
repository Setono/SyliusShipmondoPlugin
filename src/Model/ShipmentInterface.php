<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Core\Model\ShipmentInterface as BaseShipmentInterface;

interface ShipmentInterface extends BaseShipmentInterface
{
    public function setShipmondoPickupPoint(?array $shipmondoPickupPoint): void;

    public function getShipmondoPickupPoint(): ?array;
}
