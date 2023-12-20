<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\DTO\Model\Address;
use Setono\Shipmondo\DTO\Model\SalesOrder;
use Setono\SyliusShipmondoPlugin\Event\MapOrderLineEvent;
use Setono\SyliusShipmondoPlugin\Event\MapSalesOrderEvent;
use Setono\SyliusShipmondoPlugin\Event\MapShippingOrderLineEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;
use Webmozart\Assert\Assert;

final class SalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function map(OrderInterface $order): SalesOrder
    {
        $salesOrder = new SalesOrder(
            orderId: (string) $order->getNumber(),
            orderedAt: $order->getCheckoutCompletedAt(),
            sourceName: 'Sylius',
            orderNote: $order->getNotes(),
            shipTo: new Address(
                name: $order->getShippingAddress()?->getFullName(),
                address1: $order->getShippingAddress()?->getStreet(),
                zipCode: $order->getShippingAddress()?->getPostcode(),
                city: $order->getShippingAddress()?->getCity(),
                countryCode: $order->getShippingAddress()?->getCountryCode(),
                email: $order->getCustomer()?->getEmail(),
                mobile: $order->getShippingAddress()?->getPhoneNumber(),
            ),
            billTo: new Address(
                name: $order->getBillingAddress()?->getFullName(),
                address1: $order->getBillingAddress()?->getStreet(),
                zipCode: $order->getBillingAddress()?->getPostcode(),
                city: $order->getBillingAddress()?->getCity(),
                countryCode: $order->getBillingAddress()?->getCountryCode(),
                email: $order->getCustomer()?->getEmail(),
                mobile: $order->getBillingAddress()?->getPhoneNumber(),
            ),
            paymentDetails: new SalesOrder\PaymentDetails(
                amountIncludingVat: self::formatAmount($order->getTotal()),
                currencyCode: $order->getCurrencyCode(),
                vatAmount: self::formatAmount($order->getTaxTotal()),
                paymentMethod: self::getPaymentMethod($order),
            ),
        );

        foreach ($order->getItems() as $orderItem) {
            /** @var OrderItemUnitInterface|false $orderItemUnit */
            $orderItemUnit = $orderItem->getUnits()->first();
            Assert::isInstanceOf($orderItemUnit, OrderItemUnitInterface::class);

            $orderLine = new SalesOrder\OrderLine(
                itemName: sprintf('%s (%s)', (string) $orderItem->getProductName(), (string) $orderItem->getProductName()),
                itemSku: $orderItem->getVariant()?->getCode(),
                quantity: $orderItem->getQuantity(),
                unitPriceExcludingVat: self::formatAmount($orderItem->getUnitPrice() - $orderItemUnit->getTaxTotal()),
                currencyCode: $order->getCurrencyCode(),
            );

            $this->eventDispatcher->dispatch(new MapOrderLineEvent($orderLine, $orderItem, $order));

            $salesOrder->orderLines[] = $orderLine;
        }

        $shippingAdjustments = $order->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT);
        foreach ($shippingAdjustments as $shippingAdjustment) {
            $orderLine = new SalesOrder\OrderLine(
                lineType: SalesOrder\OrderLine::LINE_TYPE_SHIPPING,
                itemName: $shippingAdjustment->getLabel(),
                quantity: 1,
                unitPriceExcludingVat: self::formatAmount($shippingAdjustment->getAmount()),
                currencyCode: $order->getCurrencyCode(),
            );

            $this->eventDispatcher->dispatch(new MapShippingOrderLineEvent($orderLine, $shippingAdjustment, $order));

            $salesOrder->orderLines[] = $orderLine;
        }

        $this->eventDispatcher->dispatch(new MapSalesOrderEvent($salesOrder, $order));

        return $salesOrder;
    }

    private static function formatAmount(int $amount): string
    {
        return (string) round($amount / 100, 2);
    }

    private static function getPaymentMethod(OrderInterface $order): ?string
    {
        $payment = $order->getPayments()->first();
        if (false === $payment) {
            return null;
        }

        return $payment->getMethod()?->getName();
    }
}
