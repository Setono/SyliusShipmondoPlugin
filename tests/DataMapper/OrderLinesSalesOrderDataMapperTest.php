<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\DataMapper;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\DataMapper\OrderLinesSalesOrderDataMapper;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Payment;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\PaymentMethod;

final class OrderLinesSalesOrderDataMapperTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_maps_order_lines_with_tax_included_in_price(): void
    {
        $order = self::getOrder();
        $order->addItem(self::getOrderItem(100, 20, true));
        $order->addItem(self::getOrderItem(200, 40, true));

        $salesOrder = new SalesOrder();

        $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);

        $mapper = new OrderLinesSalesOrderDataMapper($eventDispatcher->reveal());
        $mapper->map($order, $salesOrder);

        self::assertCount(2, $salesOrder->orderLines);
        self::assertSame('0.8', $salesOrder->orderLines[0]->unitPriceExcludingVat);
        self::assertSame(1, $salesOrder->orderLines[0]->quantity);
        self::assertSame('0.25', $salesOrder->orderLines[0]->vatPercent);

        self::assertSame('1.6', $salesOrder->orderLines[1]->unitPriceExcludingVat);
        self::assertSame(1, $salesOrder->orderLines[1]->quantity);
        self::assertSame('0.25', $salesOrder->orderLines[1]->vatPercent);
    }

    /**
     * @test
     */
    public function it_maps_order_lines_with_tax_not_included_in_price(): void
    {
        $order = self::getOrder();
        $order->addItem(self::getOrderItem(100, 20, false));
        $order->addItem(self::getOrderItem(200, 40, false));

        $salesOrder = new SalesOrder();

        $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);

        $mapper = new OrderLinesSalesOrderDataMapper($eventDispatcher->reveal());
        $mapper->map($order, $salesOrder);

        self::assertCount(2, $salesOrder->orderLines);
        self::assertSame('1', $salesOrder->orderLines[0]->unitPriceExcludingVat);
        self::assertSame(1, $salesOrder->orderLines[0]->quantity);
        self::assertSame('0.2', $salesOrder->orderLines[0]->vatPercent);
        self::assertSame('T-Shirt (XL)', $salesOrder->orderLines[0]->itemName);

        self::assertSame('2', $salesOrder->orderLines[1]->unitPriceExcludingVat);
        self::assertSame(1, $salesOrder->orderLines[1]->quantity);
        self::assertSame('0.2', $salesOrder->orderLines[1]->vatPercent);
        self::assertSame('T-Shirt (XL)', $salesOrder->orderLines[1]->itemName);
    }

    private static function getOrder(): Order
    {
        $order = new Order();
        $order->setCurrencyCode('USD');

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setName('Credit Card');
        $paymentMethod->setShipmondoId(123);

        $payment = new Payment();
        $payment->setMethod($paymentMethod);

        $order->addPayment($payment);

        return $order;
    }

    private static function getOrderItem(int $unitPrice, int $taxAmount, bool $taxNeutral): OrderItem
    {
        $orderItem = new OrderItem();
        $orderItem->setUnitPrice($unitPrice);

        $orderItem->setProductName('T-Shirt');
        $orderItem->setVariantName('XL');

        $taxAdjustment = new Adjustment();
        $taxAdjustment->setAmount($taxAmount);
        $taxAdjustment->setNeutral($taxNeutral);
        $taxAdjustment->setType(AdjustmentInterface::TAX_ADJUSTMENT);

        $orderItemUnit = new OrderItemUnit($orderItem);
        $orderItemUnit->addAdjustment($taxAdjustment);

        return $orderItem;
    }
}
