<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Request\SalesOrder\PaymentDetails;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\PaymentDetailsSalesOrderDataMapper;
use Setono\SyliusShipmondoPlugin\Provider\SetupProvider;
use Sylius\Component\Core\Model\Adjustment;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\OrderItemUnit;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentInterface;
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
        $this->mapper = new PaymentDetailsSalesOrderDataMapper(new SetupProvider(ShipmondoApi::client($this->httpClient)), $this->logger);
    }

    /**
     * @test
     *
     * @dataProvider authorizedOrCompletedPaymentStates
     */
    public function it_maps_an_order_paid_by_one_gateway_payment(string $state): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(122), self::paymentGateway(123)]);
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::orderTotalling(14077, [self::payment(self::card(123), 14077, $state)]), $salesOrder);

        self::assertEquals(new PaymentDetails(
            amountIncludingVat: '140.77',
            vatAmount: '0',
            currencyCode: 'USD',
            amountExcludingVat: '140.77',
            authorizedAmount: '140.77',
            vatPercent: '0',
            paymentMethod: 'Card',
            paymentGatewayId: '123',
        ), $salesOrder->paymentDetails);
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function authorizedOrCompletedPaymentStates(): iterable
    {
        yield 'authorized' => [PaymentInterface::STATE_AUTHORIZED];
        yield 'completed' => [PaymentInterface::STATE_COMPLETED];
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

        $this->mapper->map(self::orderTotalling(14077, [
            self::payment(self::card(123), 14077, PaymentInterface::STATE_AUTHORIZED),
        ]), $salesOrder);

        self::assertSame('123', $salesOrder->paymentDetails?->paymentGatewayId);
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => 'The payment method {paymentMethod} of the order {order} is mapped to the payment gateway {paymentGateway}, which no longer exists in Shipmondo, so Shipmondo may not capture the order\'s payment. Map the payment method to an existing payment gateway, or remove the mapping, on the admin Shipmondo page',
            'context' => [
                'order' => '000001',
                'paymentMethod' => 'card',
                'paymentGateway' => 123,
            ],
        ]], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_maps_payment_details_of_an_order_without_payments(): void
    {
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::order(), $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertSame('USD', $salesOrder->paymentDetails->currencyCode);
        self::assertNull($salesOrder->paymentDetails->authorizedAmount);
        self::assertNull($salesOrder->paymentDetails->paymentMethod);
        self::assertNull($salesOrder->paymentDetails->paymentGatewayId);
        // an empty order has no amount to derive a VAT percentage from
        self::assertSame('0', $salesOrder->paymentDetails->vatPercent);
    }

    /**
     * @test
     */
    public function it_rejects_a_payment_method_that_is_not_a_shipmondo_payment_method(): void
    {
        $payment = new Payment();
        $payment->setMethod(new BasePaymentMethod());
        $payment->setState(PaymentInterface::STATE_COMPLETED);

        $this->expectException(\InvalidArgumentException::class);

        $this->mapper->map(self::order([$payment]), new SalesOrderRequest());
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

        $order = self::order();
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

        $order = self::order();
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
     * A gift card pays part of the order, and the card payment's payment gateway holds the rest, which is all Shipmondo
     * can capture
     *
     * @test
     */
    public function it_sends_what_the_gateway_payment_holds_as_the_authorized_amount(): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(123)]);
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::orderTotalling(14077, [
            self::payment(self::card(123), 11077, PaymentInterface::STATE_AUTHORIZED),
            self::payment(self::giftCard(), 3000, PaymentInterface::STATE_COMPLETED),
        ]), $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertSame('110.77', $salesOrder->paymentDetails->authorizedAmount);
        self::assertSame('Card', $salesOrder->paymentDetails->paymentMethod);
        self::assertSame('123', $salesOrder->paymentDetails->paymentGatewayId);
        // the order total, whoever pays it
        self::assertSame('140.77', $salesOrder->paymentDetails->amountIncludingVat);
        self::assertSame([], $this->logger->records);
    }

    /**
     * Two payment methods can be mapped to one payment gateway, e.g. cards and MobilePay through QuickPay
     *
     * @test
     */
    public function it_sums_the_gateway_payments_through_one_payment_gateway_and_names_the_largest(): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(123)]);
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::orderTotalling(14077, [
            self::payment(self::card(123), 4000, PaymentInterface::STATE_AUTHORIZED),
            self::payment(self::mobilePay(123), 10077, PaymentInterface::STATE_COMPLETED),
        ]), $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertSame('140.77', $salesOrder->paymentDetails->authorizedAmount);
        self::assertSame('MobilePay', $salesOrder->paymentDetails->paymentMethod);
        self::assertSame('123', $salesOrder->paymentDetails->paymentGatewayId);
        self::assertSame([], $this->logger->records);
    }

    /**
     * A sales order carries one payment gateway, so Shipmondo can capture none of the payments
     *
     * @test
     */
    public function it_sends_no_payment_gateway_and_no_authorized_amount_when_the_gateway_payments_go_through_several_payment_gateways(): void
    {
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::orderTotalling(14077, [
            self::payment(self::payPal(456), 4000, PaymentInterface::STATE_COMPLETED),
            self::payment(self::card(123), 10077, PaymentInterface::STATE_AUTHORIZED),
        ]), $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertNull($salesOrder->paymentDetails->authorizedAmount);
        self::assertNull($salesOrder->paymentDetails->paymentGatewayId);
        self::assertSame('Card', $salesOrder->paymentDetails->paymentMethod);
        self::assertSame('140.77', $salesOrder->paymentDetails->amountIncludingVat);
        self::assertSame([], $this->httpClient->requests());
        self::assertSame([[
            'level' => LogLevel::WARNING,
            'message' => 'The order {order} is paid through several payment gateways, but a sales order carries only one, so the order is uploaded without a payment gateway and an authorized amount, and Shipmondo can\'t capture the order\'s payment',
            'context' => [
                'order' => '000001',
                'paymentGateways' => [456, 123],
            ],
        ]], $this->logger->records);
    }

    /**
     * No payment gateway holds any of the order's payments, e.g. it is paid with gift cards alone, or the shop maps no
     * payment method to a payment gateway, so Shipmondo has nothing to capture
     *
     * @test
     *
     * @dataProvider ordersWithoutGatewayPayments
     */
    public function it_sends_no_payment_gateway_and_no_authorized_amount_when_no_payment_is_a_gateway_payment(Order $order, string $paymentMethod): void
    {
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map($order, $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertNull($salesOrder->paymentDetails->authorizedAmount);
        self::assertNull($salesOrder->paymentDetails->paymentGatewayId);
        self::assertSame($paymentMethod, $salesOrder->paymentDetails->paymentMethod);
        self::assertSame('140.77', $salesOrder->paymentDetails->amountIncludingVat);
        self::assertSame([], $this->httpClient->requests());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{Order, string}>
     */
    public static function ordersWithoutGatewayPayments(): iterable
    {
        yield 'gift cards alone' => [self::orderTotalling(14077, [
            self::payment(self::giftCard(), 4000, PaymentInterface::STATE_COMPLETED),
            self::payment(self::giftCard(), 10077, PaymentInterface::STATE_COMPLETED),
        ]), 'Gift card'];

        yield 'a shop that maps no payment method' => [self::orderTotalling(14077, [
            self::payment(self::giftCard(), 4000, PaymentInterface::STATE_COMPLETED),
            self::payment(self::card(null), 10077, PaymentInterface::STATE_AUTHORIZED),
        ]), 'Card'];
    }

    /**
     * Sylius adds a new payment when one fails, and the customer can pay it with another payment method
     *
     * @test
     */
    public function it_maps_the_payment_that_paid_the_order_after_a_failed_one(): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(123), self::paymentGateway(456)]);
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::orderTotalling(14077, [
            self::payment(self::paymentMethod('bank_transfer', 'Bank transfer', 456), 14077, PaymentInterface::STATE_FAILED),
            self::payment(self::card(123), 14077, PaymentInterface::STATE_AUTHORIZED),
        ]), $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertSame('140.77', $salesOrder->paymentDetails->authorizedAmount);
        self::assertSame('Card', $salesOrder->paymentDetails->paymentMethod);
        self::assertSame('123', $salesOrder->paymentDetails->paymentGatewayId);
        self::assertSame([], $this->logger->records);
    }

    /**
     * Only an authorized or completed payment pays the order
     *
     * @test
     *
     * @dataProvider unauthorizedOrCompletedPaymentStates
     */
    public function it_ignores_a_payment_that_is_neither_authorized_nor_completed(string $state): void
    {
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::orderTotalling(14077, [
            self::payment(self::card(123), 14077, $state),
            self::payment(self::payPal(456), 14077, $state),
        ]), $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertNull($salesOrder->paymentDetails->authorizedAmount);
        self::assertNull($salesOrder->paymentDetails->paymentGatewayId);
        self::assertNull($salesOrder->paymentDetails->paymentMethod);
        self::assertSame('140.77', $salesOrder->paymentDetails->amountIncludingVat);
        self::assertSame([], $this->httpClient->requests());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unauthorizedOrCompletedPaymentStates(): iterable
    {
        foreach ([
            PaymentInterface::STATE_CART,
            PaymentInterface::STATE_NEW,
            PaymentInterface::STATE_PROCESSING,
            PaymentInterface::STATE_FAILED,
            PaymentInterface::STATE_CANCELLED,
            PaymentInterface::STATE_REFUNDED,
            PaymentInterface::STATE_UNKNOWN,
        ] as $state) {
            yield $state => [$state];
        }
    }

    /**
     * @test
     */
    public function it_counts_a_payment_without_a_payment_method_as_no_gateway_payment(): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(123)]);
        $withoutPaymentMethod = new Payment();
        $withoutPaymentMethod->setAmount(10077);
        $withoutPaymentMethod->setState(PaymentInterface::STATE_COMPLETED);
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::orderTotalling(14077, [
            $withoutPaymentMethod,
            self::payment(self::card(123), 4000, PaymentInterface::STATE_AUTHORIZED),
        ]), $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertSame('40', $salesOrder->paymentDetails->authorizedAmount);
        self::assertSame('Card', $salesOrder->paymentDetails->paymentMethod);
        self::assertSame('123', $salesOrder->paymentDetails->paymentGatewayId);
    }

    /**
     * Sylius's own payments start at 0, but a payment can have no amount
     *
     * @test
     */
    public function it_counts_a_payment_without_an_amount_as_paying_nothing(): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(123)]);
        $withoutAmount = new class() extends Payment {
            public function getAmount(): ?int
            {
                return null;
            }
        };
        $withoutAmount->setMethod(self::mobilePay(123));
        $withoutAmount->setState(PaymentInterface::STATE_AUTHORIZED);
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map(self::orderTotalling(14077, [
            $withoutAmount,
            self::payment(self::card(123), 14077, PaymentInterface::STATE_AUTHORIZED),
        ]), $salesOrder);

        self::assertNotNull($salesOrder->paymentDetails);
        self::assertSame('140.77', $salesOrder->paymentDetails->authorizedAmount);
        self::assertSame('Card', $salesOrder->paymentDetails->paymentMethod);
    }

    /**
     * @test
     *
     * @dataProvider paymentsOfTheSameAmount
     */
    public function it_names_the_earliest_payment_method_when_the_largest_payments_are_of_the_same_amount(Order $order, string $paymentMethod): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(123)]);
        $salesOrder = new SalesOrderRequest();

        $this->mapper->map($order, $salesOrder);

        self::assertSame($paymentMethod, $salesOrder->paymentDetails?->paymentMethod);
    }

    /**
     * @return iterable<string, array{Order, string}>
     */
    public static function paymentsOfTheSameAmount(): iterable
    {
        yield 'gateway payments' => [self::orderTotalling(14077, [
            self::payment(self::card(123), 77, PaymentInterface::STATE_COMPLETED),
            self::payment(self::mobilePay(123), 7000, PaymentInterface::STATE_AUTHORIZED),
            self::payment(self::card(123), 7000, PaymentInterface::STATE_AUTHORIZED),
        ]), 'MobilePay'];

        yield 'payments without a payment gateway' => [self::orderTotalling(14077, [
            self::payment(self::card(null), 77, PaymentInterface::STATE_COMPLETED),
            self::payment(self::giftCard(), 7000, PaymentInterface::STATE_COMPLETED),
            self::payment(self::card(null), 7000, PaymentInterface::STATE_AUTHORIZED),
        ]), 'Gift card'];
    }

    /**
     * An order whose total is a single item's price, without tax
     *
     * @param list<Payment> $payments
     */
    private static function orderTotalling(int $total, array $payments): Order
    {
        $orderItem = new OrderItem();
        $orderItem->setUnitPrice($total);
        new OrderItemUnit($orderItem);

        $order = self::order($payments);
        $order->addItem($orderItem);

        return $order;
    }

    /**
     * @param list<Payment> $payments
     */
    private static function order(array $payments = []): Order
    {
        $order = new Order();
        $order->setNumber('000001');
        $order->setCurrencyCode('USD');
        foreach ($payments as $payment) {
            $order->addPayment($payment);
        }

        return $order;
    }

    private static function payment(PaymentMethod $paymentMethod, int $amount, string $state): Payment
    {
        $payment = new Payment();
        $payment->setMethod($paymentMethod);
        $payment->setAmount($amount);
        $payment->setState($state);

        return $payment;
    }

    private static function card(?int $paymentGateway): PaymentMethod
    {
        return self::paymentMethod('card', 'Card', $paymentGateway);
    }

    private static function mobilePay(?int $paymentGateway): PaymentMethod
    {
        return self::paymentMethod('mobile_pay', 'MobilePay', $paymentGateway);
    }

    private static function payPal(int $paymentGateway): PaymentMethod
    {
        return self::paymentMethod('paypal', 'PayPal', $paymentGateway);
    }

    private static function giftCard(): PaymentMethod
    {
        return self::paymentMethod('gift_card', 'Gift card', null);
    }

    private static function paymentMethod(string $code, string $name, ?int $paymentGateway): PaymentMethod
    {
        $paymentMethod = new PaymentMethod();
        $paymentMethod->setCode($code);
        $paymentMethod->setCurrentLocale('en_US');
        $paymentMethod->setName($name);
        $paymentMethod->setShipmondoId($paymentGateway);

        return $paymentMethod;
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentGateway(int $id): array
    {
        return ['id' => $id, 'name' => sprintf('Gateway %d', $id), 'provider' => 'quickpay', 'merchant_number' => '1234'];
    }
}
