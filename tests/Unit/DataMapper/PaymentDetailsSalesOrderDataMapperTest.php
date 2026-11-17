<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\PaymentDetailsSalesOrderDataMapper;
use Setono\SyliusShipmondoPlugin\Provider\ShipmondoSetupProvider;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod as BasePaymentMethod;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\PaymentMethod;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;

final class PaymentDetailsSalesOrderDataMapperTest extends TestCase
{
    private ScriptedHttpClient $httpClient;

    private RecordingLogger $logger;

    private PaymentDetailsSalesOrderDataMapper $mapper;

    protected function setUp(): void
    {
        $this->httpClient = new ScriptedHttpClient();
        $this->logger = new RecordingLogger();
        $this->mapper = new PaymentDetailsSalesOrderDataMapper(new ShipmondoSetupProvider(ShipmondoApi::client($this->httpClient)), $this->logger);
    }

    /**
     * @test
     */
    public function it_maps_payment_details(): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(122), self::paymentGateway(123)]);
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::getOrder(123), $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertSame('USD', $salesOrder->paymentDetails->currencyCode);
        self::assertSame('Credit Card', $salesOrder->paymentDetails->paymentMethod);
        self::assertSame('123', $salesOrder->paymentDetails->paymentGatewayId);
        // an empty order has no amount to derive a VAT percentage from
        self::assertSame('0', $salesOrder->paymentDetails->vatPercent);
        self::assertSame([], $this->logger->records);
    }

    /**
     * Shipmondo accepts a sales order with a payment gateway it doesn't have, so the order is uploaded as it is, and the
     * upload logs an error: Shipmondo may not capture the payment. The mapping stays until an admin changes it
     *
     * @test
     */
    public function it_logs_an_error_when_the_payment_method_is_mapped_to_a_payment_gateway_that_no_longer_exists_in_shipmondo(): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(122)]);
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::getOrder(123), $salesOrder);

        self::assertSame('123', $salesOrder->paymentDetails?->paymentGatewayId);
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => 'The payment method {paymentMethod} of the order {order} is mapped to the payment gateway {paymentGateway}, which no longer exists in Shipmondo, so Shipmondo may not capture the order\'s payment. Map the payment method to an existing payment gateway, or remove the mapping, on the admin Shipmondo page',
            'context' => [
                'order' => '000001',
                'paymentMethod' => 'credit_card',
                'paymentGateway' => 123,
            ],
        ]], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_maps_no_payment_gateway_for_an_unmapped_payment_method(): void
    {
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::getOrder(), $salesOrder);

        self::assertSame('Credit Card', $salesOrder->paymentDetails?->paymentMethod);
        self::assertNull($salesOrder->paymentDetails->paymentGatewayId);
        self::assertSame([], $this->httpClient->requests());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_maps_payment_details_of_an_order_without_payments(): void
    {
        $order = new Order();
        $order->setCurrencyCode('USD');
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map($order, $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertNull($salesOrder->paymentDetails->paymentMethod);
        self::assertNull($salesOrder->paymentDetails->paymentGatewayId);
    }

    /**
     * @test
     */
    public function it_rejects_a_payment_method_that_is_not_a_shipmondo_payment_method(): void
    {
        $payment = new Payment();
        $payment->setMethod(new BasePaymentMethod());

        $order = new Order();
        $order->addPayment($payment);

        $this->expectException(\InvalidArgumentException::class);

        $this->mapper->map($order, new SalesOrderRequest());
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
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map($order, $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
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
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map($order, $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertSame('1.25', $salesOrder->paymentDetails->amountIncludingVat);
        self::assertSame('1', $salesOrder->paymentDetails->amountExcludingVat);
        self::assertSame('0.25', $salesOrder->paymentDetails->vatAmount);
        self::assertSame('0.25', $salesOrder->paymentDetails->vatPercent);
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentGateway(int $id): array
    {
        return ['id' => $id, 'name' => sprintf('Gateway %d', $id), 'provider' => 'quickpay', 'merchant_number' => '1234'];
    }

    private static function getOrder(?int $paymentGateway = null): Order
    {
        $order = new Order();
        $order->setNumber('000001');
        $order->setCurrencyCode('USD');

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode('credit_card');
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setName('Credit Card');
        $paymentMethod->setShipmondoId($paymentGateway);

        $payment = new Payment();
        $payment->setMethod($paymentMethod);

        $order->addPayment($payment);

        return $order;
    }
}
