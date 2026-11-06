<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\Shipmondo\Request\SalesOrder\PaymentDetails;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use function Setono\SyliusShipmondoPlugin\formatAmount;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Webmozart\Assert\Assert;

final class PaymentDetailsSalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function map(OrderInterface $order, SalesOrderRequest $salesOrder): void
    {
        $paymentMethod = self::getPaymentMethod($order);

        $amount = AmountWithVat::includingVat($order->getTotal(), $order->getTaxTotal());

        $salesOrder->paymentDetails = new PaymentDetails(
            amountExcludingVat: formatAmount($amount->excludingVat),
            amountIncludingVat: formatAmount($order->getTotal()),
            authorizedAmount: formatAmount($order->getTotal()),
            currencyCode: $order->getCurrencyCode(),
            vatAmount: formatAmount($amount->vat),
            vatPercent: $amount->vatPercent(),
            paymentMethod: $paymentMethod?->getName(),
            paymentGatewayId: null === $paymentMethod ? null : (string) $paymentMethod->getShipmondoId(),
        );
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
