<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\ShippingSalesOrderDataMapper;
use Setono\SyliusShipmondoPlugin\Event\ShippingOrderLineMappedEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\Shipment;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Order\Model\Adjustment as BaseAdjustment;
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

        $salesOrder = new SalesOrderRequest();

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

        $salesOrder = new SalesOrderRequest();

        $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);

        $mapper = new ShippingSalesOrderDataMapper($eventDispatcher->reveal());
        $mapper->map($order, $salesOrder);

        self::assertCount(1, $salesOrder->orderLines);
        self::assertSame('1', $salesOrder->orderLines[0]->unitPriceExcludingVat);
        self::assertSame(1, $salesOrder->orderLines[0]->quantity);
        self::assertSame('0.2', $salesOrder->orderLines[0]->vatPercent);
    }

    /**
     * @test
     */
    public function it_maps_free_shipping_with_a_zero_vat_percentage(): void
    {
        $order = self::getOrder();
        self::addShipmentWith($order, self::adjustment(AdjustmentInterface::SHIPPING_ADJUSTMENT, 0));

        $salesOrder = $this->map($order);

        self::assertSame('0', $salesOrder->orderLines[0]->unitPriceExcludingVat);
        self::assertSame('0', $salesOrder->orderLines[0]->vatPercent);
    }

    /**
     * @test
     */
    public function it_maps_untaxed_shipping_with_a_zero_vat_percentage(): void
    {
        $order = self::getOrder();
        self::addShipmentWith($order, self::adjustment(AdjustmentInterface::SHIPPING_ADJUSTMENT, 100));

        $salesOrder = $this->map($order);

        self::assertSame('1', $salesOrder->orderLines[0]->unitPriceExcludingVat);
        self::assertSame('0', $salesOrder->orderLines[0]->vatPercent);
    }

    /**
     * @test
     */
    public function it_dispatches_an_event_for_the_shipping_line(): void
    {
        $order = self::getOrder();
        self::addShipment($order, 100, 20, false);

        $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(Argument::type(ShippingOrderLineMappedEvent::class))->willReturnArgument(0)->shouldBeCalledOnce();

        (new ShippingSalesOrderDataMapper($eventDispatcher->reveal()))->map($order, new SalesOrderRequest());
    }

    /**
     * @test
     */
    public function it_rejects_a_shipment_with_more_than_one_shipping_adjustment(): void
    {
        $order = self::getOrder();
        self::addShipmentWith(
            $order,
            self::adjustment(AdjustmentInterface::SHIPPING_ADJUSTMENT, 100),
            self::adjustment(AdjustmentInterface::SHIPPING_ADJUSTMENT, 50),
        );

        $this->expectException(\InvalidArgumentException::class);

        $this->map($order);
    }

    /**
     * @test
     */
    public function it_rejects_a_shipment_with_more_than_one_tax_adjustment(): void
    {
        $order = self::getOrder();
        self::addShipmentWith(
            $order,
            self::adjustment(AdjustmentInterface::SHIPPING_ADJUSTMENT, 100),
            self::adjustment(AdjustmentInterface::TAX_ADJUSTMENT, 20),
            self::adjustment(AdjustmentInterface::TAX_ADJUSTMENT, 10),
        );

        $this->expectException(\InvalidArgumentException::class);

        $this->map($order);
    }

    /**
     * @test
     */
    public function it_rejects_a_shipping_adjustment_that_is_not_a_core_adjustment(): void
    {
        $shippingAdjustment = new BaseAdjustment();
        $shippingAdjustment->setType(AdjustmentInterface::SHIPPING_ADJUSTMENT);

        $shipment = $this->prophesize(ShipmentInterface::class);
        $shipment->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT)->willReturn(new ArrayCollection([$shippingAdjustment]));

        $order = $this->prophesize(OrderInterface::class);
        $order->getShipments()->willReturn(new ArrayCollection([$shipment->reveal()]));
        $order->getCurrencyCode()->willReturn('USD');

        $this->expectException(\InvalidArgumentException::class);

        $this->map($order->reveal());
    }

    private function map(OrderInterface $order): SalesOrderRequest
    {
        $salesOrder = new SalesOrderRequest();

        (new ShippingSalesOrderDataMapper($this->prophesize(EventDispatcherInterface::class)->reveal()))->map($order, $salesOrder);

        return $salesOrder;
    }

    private static function addShipmentWith(OrderInterface $order, Adjustment ...$adjustments): void
    {
        $shipment = new Shipment();
        $order->addShipment($shipment);

        foreach ($adjustments as $adjustment) {
            $shipment->addAdjustment($adjustment);
        }
    }

    private static function adjustment(string $type, int $amount): Adjustment
    {
        $adjustment = new Adjustment();
        $adjustment->setType($type);
        $adjustment->setAmount($amount);
        $adjustment->setNeutral(false);

        return $adjustment;
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
