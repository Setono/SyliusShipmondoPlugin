<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Model;

use PHPUnit\Framework\TestCase;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Shipment;

final class ShipmentTraitTest extends TestCase
{
    /**
     * @test
     */
    public function it_holds_the_chosen_pickup_point(): void
    {
        $shipment = new Shipment();
        self::assertNull($shipment->getShipmondoPickupPoint());

        $shipment->setShipmondoPickupPoint(['id' => '95558', 'name' => 'Pakkeshop']);
        self::assertSame(['id' => '95558', 'name' => 'Pakkeshop'], $shipment->getShipmondoPickupPoint());

        $shipment->setShipmondoPickupPoint(null);
        self::assertNull($shipment->getShipmondoPickupPoint());
    }
}
