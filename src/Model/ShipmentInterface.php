<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Setono\Shipmondo\Response\PickupPoint\PickupPoint;
use Sylius\Component\Core\Model\ShipmentInterface as BaseShipmentInterface;

interface ShipmentInterface extends BaseShipmentInterface
{
    /**
     * The pickup point the customer chose in checkout. Only a shipment whose shipping method has pickup point delivery
     * has one; the checkout makes sure of that
     */
    public function setShipmondoPickupPoint(?PickupPoint $shipmondoPickupPoint): void;

    public function getShipmondoPickupPoint(): ?PickupPoint;
}
