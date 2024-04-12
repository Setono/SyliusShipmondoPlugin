<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\DataMapper;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\DataMapper\ShippingSalesOrderDataMapper;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\Shipment;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

final class ShippingSalesOrderDataMapperTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_maps_shipping_with_tax_included_in_price(): void
    {
        $order = self::getOrder();
        self::addShipment($order, 100, 20, true);

        $salesOrder = new SalesOrder();

        $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);

        $mapper = new ShippingSalesOrderDataMapper($eventDispatcher->reveal());
        $mapper->map($order, $salesOrder);

        self::assertCount(1, $salesOrder->orderLines);
        self::assertSame('0.8', $salesOrder->orderLines[0]->unitPriceExcludingVat);
        self::assertSame(1, $salesOrder->orderLines[0]->quantity);
        self::assertSame('0.25', $salesOrder->orderLines[0]->vatPercent);
    }

    /**
     * @test
     */
    public function it_maps_shipping_with_tax_not_included_in_price(): void
    {
        $order = self::getOrder();
        self::addShipment($order, 100, 20, false);

        $salesOrder = new SalesOrder();

        $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);

        $mapper = new ShippingSalesOrderDataMapper($eventDispatcher->reveal());
        $mapper->map($order, $salesOrder);

        self::assertCount(1, $salesOrder->orderLines);
        self::assertSame('1', $salesOrder->orderLines[0]->unitPriceExcludingVat);
        self::assertSame(1, $salesOrder->orderLines[0]->quantity);
        self::assertSame('0.2', $salesOrder->orderLines[0]->vatPercent);
    }

    private static function getOrder(): Order
    {
        $order = new Order();
        $order->setCurrencyCode('USD');

        return $order;
    }

    private static function addShipment(OrderInterface $order, int $shippingAmount, int $taxAmount, bool $taxNeutral): void
    {
        $shipment = new Shipment();
        $order->addShipment($shipment);

        $taxAdjustment = new Adjustment();
        $taxAdjustment->setAmount($taxAmount);
        $taxAdjustment->setNeutral($taxNeutral);
        $taxAdjustment->setType(AdjustmentInterface::TAX_ADJUSTMENT);

        $shippingAdjustment = new Adjustment();
        $shippingAdjustment->setAmount($shippingAmount);
        $shippingAdjustment->setNeutral(false);
        $shippingAdjustment->setType(AdjustmentInterface::SHIPPING_ADJUSTMENT);

        $shipment->addAdjustment($shippingAdjustment);
        $shipment->addAdjustment($taxAdjustment);
    }
}
