<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Setono\Shipmondo\Response\PickupPoint\PickupPoint;
use Sylius\Component\Core\Model\ShipmentInterface as BaseShipmentInterface;

interface ShipmentInterface extends BaseShipmentInterface
{
    /**
     * The pickup point the customer chose in the shipping step of the checkout, which requires one for a shipping method
     * with pickup point delivery and drops it for any other shipping method
     */
    public function setShipmondoPickupPoint(?PickupPoint $shipmondoPickupPoint): void;

    public function getShipmondoPickupPoint(): ?PickupPoint;
}
