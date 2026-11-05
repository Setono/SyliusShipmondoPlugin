<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use PHPUnit\Framework\TestCase;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\Shipmondo\Request\SalesOrder\ServicePoint;
use Setono\SyliusShipmondoPlugin\DataMapper\ServicePointDataMapper;
use Sylius\Component\Core\Model\Shipment as BaseShipment;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Shipment;
use Tests\Setono\SyliusShipmondoPlugin\Support\PickupPoints;

final class ServicePointDataMapperTest extends TestCase
{
    /**
     * @test
     */
    public function it_maps_the_chosen_pickup_point_to_the_service_point(): void
    {
        $pickupPoint = PickupPoints::create(address2: 'Bagindgangen');
        $shipment = new Shipment();
        $shipment->setShipmondoPickupPoint($pickupPoint);
        $order = new Order();
        $order->addShipment($shipment);

        self::assertEquals(ServicePoint::fromPickupPoint($pickupPoint), self::map($order)->servicePoint);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_shipment_has_no_pickup_point(): void
    {
        $order = new Order();
        $order->addShipment(new Shipment());

        self::assertNull(self::map($order)->servicePoint);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_has_no_shipments(): void
    {
        self::assertNull(self::map(new Order())->servicePoint);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_shipment_is_not_a_shipmondo_shipment(): void
    {
        $order = new Order();
        $order->addShipment(new BaseShipment());

        self::assertNull(self::map($order)->servicePoint);
    }

    private static function map(Order $order): SalesOrderRequest
    {
        $salesOrder = new SalesOrderRequest();
        (new ServicePointDataMapper())->map($order, $salesOrder);

        return $salesOrder;
    }
}
