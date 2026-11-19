<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Request\SalesOrder\PaymentDetails;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use function Setono\SyliusShipmondoPlugin\formatAmount;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Provider\SetupProviderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Webmozart\Assert\Assert;

/**
 * Maps the order's total, and what Shipmondo can capture of it: the order's gateway payments, the authorized or
 * completed payments made with a payment method mapped to a payment gateway. When they all go through one payment
 * gateway, the sales order carries it, and their sum as its authorized amount. When they go through several, it carries
 * neither, and the mapper warns, since a sales order carries only one payment gateway. Payments in any other state are
 * ignored.
 *
 * The payment method, a label in Shipmondo, is the method of the largest gateway payment through the payment gateway
 * that is sent, or else of the largest authorized or completed payment, the earliest of them on a tie. Shipmondo
 * accepts a payment gateway that no longer exists in it, so the mapper logs an error for one
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
        $authorizedOrCompletedPayments = self::authorizedOrCompletedPayments($order);
        $gatewayPayments = self::gatewayPayments($authorizedOrCompletedPayments);

        $paymentGateway = null;
        $authorizedAmount = null;
        // the payments the payment method is named after: the gateway payments when their payment gateway is sent
        $payments = $authorizedOrCompletedPayments;

        if (1 === count($gatewayPayments)) {
            $paymentGateway = array_key_first($gatewayPayments);
            $payments = $gatewayPayments[$paymentGateway];
            $authorizedAmount = formatAmount(self::sum($payments));
        } elseif ([] !== $gatewayPayments) {
            $this->logger->warning('The order {order} is paid through several payment gateways, but a sales order carries only one, so the order is uploaded without a payment gateway and an authorized amount, and Shipmondo can\'t capture the order\'s payment', [
                'order' => $order->getNumber(),
                'paymentGateways' => array_keys($gatewayPayments),
            ]);
        }

        $paymentMethod = [] === $payments ? null : self::paymentMethod(self::largest($payments));

        if (null !== $paymentGateway && null !== $paymentMethod) {
            $this->logStalePaymentGateway($order, $paymentMethod);
        }

        $amount = AmountWithVat::fromAmountIncludingVat($order->getTotal(), $order->getTaxTotal());

        $salesOrder->paymentDetails = new PaymentDetails(
            amountExcludingVat: $amount->amountExcludingVat(),
            amountIncludingVat: $amount->amountIncludingVat(),
            authorizedAmount: $authorizedAmount,
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

    /**
     * @return list<PaymentInterface>
     */
    private static function authorizedOrCompletedPayments(OrderInterface $order): array
    {
        $authorizedOrCompletedPayments = [];
        foreach ($order->getPayments() as $payment) {
            if (in_array($payment->getState(), [PaymentInterface::STATE_AUTHORIZED, PaymentInterface::STATE_COMPLETED], true)) {
                $authorizedOrCompletedPayments[] = $payment;
            }
        }

        return $authorizedOrCompletedPayments;
    }

    /**
     * @param list<PaymentInterface> $authorizedOrCompletedPayments
     *
     * @return array<int, non-empty-list<PaymentInterface>> the gateway payments by their payment gateway
     */
    private static function gatewayPayments(array $authorizedOrCompletedPayments): array
    {
        $gatewayPayments = [];
        foreach ($authorizedOrCompletedPayments as $payment) {
            $paymentGateway = self::paymentMethod($payment)?->getShipmondoId();
            if (null !== $paymentGateway) {
                $gatewayPayments[$paymentGateway][] = $payment;
            }
        }

        return $gatewayPayments;
    }

    /**
     * @param list<PaymentInterface> $payments
     */
    private static function sum(array $payments): int
    {
        return array_sum(array_map(self::amount(...), $payments));
    }

    /**
     * The payment with the largest amount, the earliest of them on a tie
     *
     * @param non-empty-list<PaymentInterface> $payments
     */
    private static function largest(array $payments): PaymentInterface
    {
        $largest = $payments[0];
        foreach ($payments as $payment) {
            if (self::amount($payment) > self::amount($largest)) {
                $largest = $payment;
            }
        }

        return $largest;
    }

    private static function amount(PaymentInterface $payment): int
    {
        return $payment->getAmount() ?? 0;
    }

    private static function paymentMethod(PaymentInterface $payment): ?PaymentMethodInterface
    {
        $paymentMethod = $payment->getMethod();
        Assert::nullOrIsInstanceOf($paymentMethod, PaymentMethodInterface::class);

        return $paymentMethod;
    }
}
