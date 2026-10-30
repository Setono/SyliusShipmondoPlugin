<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\OrderLinesSalesOrderDataMapper;
use Setono\SyliusShipmondoPlugin\Event\OrderLineMappedEvent;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
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

        $salesOrder = new SalesOrderRequest();

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

        $salesOrder = new SalesOrderRequest();

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

    /**
     * @test
     */
    public function it_maps_the_variant_code_and_weight_and_dispatches_an_event_per_line(): void
    {
        $variant = new ProductVariant();
        $variant->setCode('T-SHIRT-XL');
        $variant->setWeight(1.5);

        $orderItem = self::getOrderItem(100, 20, false);
        $orderItem->setVariant($variant);

        $order = self::getOrder();
        $order->addItem($orderItem);

        $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(Argument::type(OrderLineMappedEvent::class))->willReturnArgument(0)->shouldBeCalledOnce();

        $salesOrder = new SalesOrderRequest();
        (new OrderLinesSalesOrderDataMapper($eventDispatcher->reveal()))->map($order, $salesOrder);

        self::assertSame('T-SHIRT-XL', $salesOrder->orderLines[0]->itemSku);
        self::assertSame(1, $salesOrder->orderLines[0]->unitWeight);
    }

    /**
     * @test
     */
    public function it_uses_an_empty_item_name_when_the_order_item_has_no_names(): void
    {
        // without stored names, Sylius falls back to the (here unnamed) variant and product
        $product = new Product();
        $product->setCurrentLocale('en_US');
        $product->setFallbackLocale('en_US');

        $variant = new ProductVariant();
        $variant->setCurrentLocale('en_US');
        $variant->setFallbackLocale('en_US');
        $product->addVariant($variant);

        $orderItem = self::getOrderItem(100, 20, false);
        $orderItem->setVariant($variant);
        $orderItem->setProductName(null);
        $orderItem->setVariantName(null);

        $order = self::getOrder();
        $order->addItem($orderItem);

        $salesOrder = new SalesOrderRequest();
        (new OrderLinesSalesOrderDataMapper($this->prophesize(EventDispatcherInterface::class)->reveal()))->map($order, $salesOrder);

        self::assertSame('', $salesOrder->orderLines[0]->itemName);
    }

    /**
     * @test
     */
    public function it_rejects_an_order_item_without_units(): void
    {
        $orderItem = new OrderItem();
        $orderItem->setUnitPrice(100);

        $order = self::getOrder();
        $order->addItem($orderItem);

        $this->expectException(\InvalidArgumentException::class);

        (new OrderLinesSalesOrderDataMapper($this->prophesize(EventDispatcherInterface::class)->reveal()))->map($order, new SalesOrderRequest());
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
