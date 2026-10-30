<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use PHPUnit\Framework\TestCase;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\ServicePointDataMapper;
use Sylius\Component\Core\Model\Shipment as BaseShipment;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Shipment;

final class ServicePointDataMapperTest extends TestCase
{
    /**
     * @test
     */
    public function it_maps_the_chosen_pickup_point_to_the_service_point(): void
    {
        $order = self::orderWithPickupPoint([
            'id' => '95558',
            'name' => 'Pakkeshop: Min Købmand',
            'address' => 'Algade 1',
            'zipcode' => '9000',
            'city' => 'Aalborg',
            'country' => 'DK',
            'address2' => 'Bagindgangen',
        ]);

        $salesOrder = self::map($order);

        self::assertNotNull($salesOrder->servicePoint);
        self::assertSame('95558', $salesOrder->servicePoint->id);
        self::assertSame('Pakkeshop: Min Købmand', $salesOrder->servicePoint->name);
        self::assertSame('Algade 1', $salesOrder->servicePoint->address1);
        self::assertSame('9000', $salesOrder->servicePoint->zipcode);
        self::assertSame('Aalborg', $salesOrder->servicePoint->city);
        self::assertSame('DK', $salesOrder->servicePoint->countryCode);
        self::assertSame('Bagindgangen', $salesOrder->servicePoint->address2);
    }

    /**
     * @test
     */
    public function it_maps_a_pickup_point_without_a_second_address_line(): void
    {
        $order = self::orderWithPickupPoint([
            'id' => '95558',
            'name' => 'Pakkeshop: Min Købmand',
            'address' => 'Algade 1',
            'zipcode' => '9000',
            'city' => 'Aalborg',
            'country' => 'DK',
        ]);

        $salesOrder = self::map($order);

        self::assertNotNull($salesOrder->servicePoint);
        self::assertSame('95558', $salesOrder->servicePoint->id);
        self::assertNull($salesOrder->servicePoint->address2);
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

    /**
     * @param array<string, mixed> $pickupPoint
     */
    private static function orderWithPickupPoint(array $pickupPoint): Order
    {
        $shipment = new Shipment();
        $shipment->setShipmondoPickupPoint($pickupPoint);

        $order = new Order();
        $order->addShipment($shipment);

        return $order;
    }

    private static function map(Order $order): SalesOrderRequest
    {
        $salesOrder = new SalesOrderRequest();
        (new ServicePointDataMapper())->map($order, $salesOrder);

        return $salesOrder;
    }
}
