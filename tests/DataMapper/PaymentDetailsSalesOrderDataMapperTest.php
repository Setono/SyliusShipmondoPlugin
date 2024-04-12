<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\DataMapper;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\DataMapper\PaymentDetailsSalesOrderDataMapper;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Payment;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\PaymentMethod;

final class PaymentDetailsSalesOrderDataMapperTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_maps_payment_details(): void
    {
        $salesOrder = new SalesOrder();

        $mapper = new PaymentDetailsSalesOrderDataMapper();
        $mapper->map(self::getOrder(), $salesOrder);

        self::assertSame('USD', $salesOrder->paymentDetails->currencyCode);
        self::assertSame('Credit Card', $salesOrder->paymentDetails->paymentMethod);
        self::assertSame('123', $salesOrder->paymentDetails->paymentGatewayId);
    }

    /**
     * @test
     */
    public function it_maps_payment_details_with_tax_included_in_price(): void
    {
        $taxAdjustment = new Adjustment();
        $taxAdjustment->setAmount(20);
        $taxAdjustment->setNeutral(true);
        $taxAdjustment->setType(AdjustmentInterface::TAX_ADJUSTMENT);

        $orderItem = new OrderItem();
        $orderItem->setUnitPrice(100);
        new OrderItemUnit($orderItem);
        $orderItem->addAdjustment($taxAdjustment);

        $order = self::getOrder();
        $order->addItem($orderItem);
        $salesOrder = new SalesOrder();

        $mapper = new PaymentDetailsSalesOrderDataMapper();
        $mapper->map($order, $salesOrder);

        self::assertSame('1', $salesOrder->paymentDetails->amountIncludingVat);
        self::assertSame('0.2', $salesOrder->paymentDetails->vatAmount);
    }

    /**
     * @test
     */
    public function it_maps_payment_details_with_tax_not_included_in_price(): void
    {
        $taxAdjustment = new Adjustment();
        $taxAdjustment->setAmount(25);
        $taxAdjustment->setNeutral(false);
        $taxAdjustment->setType(AdjustmentInterface::TAX_ADJUSTMENT);

        $orderItem = new OrderItem();
        $orderItem->setUnitPrice(100);
        new OrderItemUnit($orderItem);
        $orderItem->addAdjustment($taxAdjustment);

        $order = self::getOrder();
        $order->addItem($orderItem);
        $salesOrder = new SalesOrder();

        $mapper = new PaymentDetailsSalesOrderDataMapper();
        $mapper->map($order, $salesOrder);

        self::assertSame('1.25', $salesOrder->paymentDetails->amountIncludingVat);
        self::assertSame('0.25', $salesOrder->paymentDetails->vatAmount);
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
}
