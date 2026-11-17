<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Request\SalesOrder\PaymentDetails;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Provider\SetupProviderInterface;
use Webmozart\Assert\Assert;

/**
 * Maps the order's payment, and the payment gateway its payment method is mapped to. Shipmondo accepts a payment gateway
 * that no longer exists in it, so the mapper logs an error for one
 */
final class PaymentDetailsSalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function __construct(
        private readonly SetupProviderInterface $setupProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function map(OrderInterface $order, SalesOrderRequest $salesOrder): void
    {
        $paymentMethod = self::getPaymentMethod($order);
        $paymentGateway = $paymentMethod?->getShipmondoId();

        if (null !== $paymentMethod) {
            $this->logStalePaymentGateway($order, $paymentMethod);
        }

        $amount = AmountWithVat::fromAmountIncludingVat($order->getTotal(), $order->getTaxTotal());

        $salesOrder->paymentDetails = new PaymentDetails(
            amountExcludingVat: $amount->amountExcludingVat(),
            amountIncludingVat: $amount->amountIncludingVat(),
            authorizedAmount: $amount->amountIncludingVat(),
            currencyCode: $order->getCurrencyCode(),
            vatAmount: $amount->vatAmount(),
            vatPercent: $amount->vatPercent(),
            paymentMethod: $paymentMethod?->getName(),
            paymentGatewayId: null === $paymentGateway ? null : (string) $paymentGateway,
        );
    }

    private function logStalePaymentGateway(OrderInterface $order, PaymentMethodInterface $paymentMethod): void
    {
        $stalePaymentGateway = $this->setupProvider->stalePaymentGateway($paymentMethod);
        if (null === $stalePaymentGateway) {
            return;
        }

        $this->logger->error('The payment method {paymentMethod} of the order {order} is mapped to the payment gateway {paymentGateway}, which no longer exists in Shipmondo, so Shipmondo may not capture the order\'s payment. Map the payment method to an existing payment gateway, or remove the mapping, on the admin Shipmondo page', [
            'order' => $order->getNumber(),
            'paymentMethod' => $paymentMethod->getCode(),
            'paymentGateway' => $stalePaymentGateway,
        ]);
    }

    private static function getPaymentMethod(OrderInterface $order): ?PaymentMethodInterface
    {
        $paymentMethod = null;
        $payment = $order->getPayments()->first();
        if (false !== $payment) {
            $paymentMethod = $payment->getMethod();
        }
        Assert::nullOrIsInstanceOf($paymentMethod, PaymentMethodInterface::class);

        return $paymentMethod;
    }
}
