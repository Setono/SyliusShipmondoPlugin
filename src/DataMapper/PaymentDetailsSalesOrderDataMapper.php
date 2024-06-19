<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\Shipmondo\Request\SalesOrders\PaymentDetails;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use function Setono\SyliusShipmondoPlugin\formatAmount;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Webmozart\Assert\Assert;

final class PaymentDetailsSalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function map(OrderInterface $order, SalesOrder $salesOrder): void
    {
        $paymentMethod = self::getPaymentMethod($order);

        $amountExcludingVat = $order->getTotal() - $order->getTaxTotal();

        $salesOrder->paymentDetails = new PaymentDetails(
            amountExcludingVat: formatAmount($amountExcludingVat),
            amountIncludingVat: formatAmount($order->getTotal()),
            authorizedAmount: formatAmount($order->getTotal()),
            currencyCode: $order->getCurrencyCode(),
            vatAmount: formatAmount($order->getTaxTotal()),
            vatPercent: (string) ($order->getTaxTotal() / $amountExcludingVat),
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
