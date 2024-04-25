<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Application\Model;

use Doctrine\ORM\Mapping as ORM;
use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;
use Setono\SyliusShipmondoPlugin\Model\ShipmentTrait;
use Sylius\Component\Core\Model\Shipment as BaseShipment;

/**
 * @ORM\Entity()
 *
 * @ORM\Table(name="sylius_shipment")
 */
class Shipment extends BaseShipment implements ShipmentInterface
{
    use ShipmentTrait;
}
