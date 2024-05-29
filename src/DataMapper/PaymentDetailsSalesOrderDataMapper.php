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

        $salesOrder->paymentDetails = new PaymentDetails(
            amountIncludingVat: formatAmount($order->getTotal()), // todo this is not necessarily correct
            currencyCode: $order->getCurrencyCode(),
            vatAmount: formatAmount($order->getTaxTotal()),
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
