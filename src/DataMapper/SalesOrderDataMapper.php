<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrders\Address;
use Setono\Shipmondo\Request\SalesOrders\OrderLine;
use Setono\Shipmondo\Request\SalesOrders\PaymentDetails;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
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

    public function map(OrderInterface $order, SalesOrder $salesOrder): void
    {
        $salesOrder->orderId = (string) $order->getNumber();
        $salesOrder->orderedAt = $order->getCheckoutCompletedAt();
        $salesOrder->sourceName = 'Sylius';
        $salesOrder->orderNote = $order->getNotes();

        $salesOrder->shipTo = new Address(
            name: $order->getShippingAddress()?->getFullName(),
            address1: $order->getShippingAddress()?->getStreet(),
            zipCode: $order->getShippingAddress()?->getPostcode(),
            city: $order->getShippingAddress()?->getCity(),
            countryCode: $order->getShippingAddress()?->getCountryCode(),
            email: $order->getCustomer()?->getEmail(),
            mobile: $order->getShippingAddress()?->getPhoneNumber(),
        );

        $salesOrder->billTo = new Address(
            name: $order->getBillingAddress()?->getFullName(),
            address1: $order->getBillingAddress()?->getStreet(),
            zipCode: $order->getBillingAddress()?->getPostcode(),
            city: $order->getBillingAddress()?->getCity(),
            countryCode: $order->getBillingAddress()?->getCountryCode(),
            email: $order->getCustomer()?->getEmail(),
            mobile: $order->getBillingAddress()?->getPhoneNumber(),
        );

        $salesOrder->paymentDetails = new PaymentDetails(
            amountIncludingVat: self::formatAmount($order->getTotal()), // todo this is not necessarily correct
            currencyCode: $order->getCurrencyCode(),
            vatAmount: self::formatAmount($order->getTaxTotal()),
            paymentMethod: self::getPaymentMethod($order),
        );

        foreach ($order->getItems() as $orderItem) {
            /** @var OrderItemUnitInterface|false $orderItemUnit */
            $orderItemUnit = $orderItem->getUnits()->first();
            Assert::isInstanceOf($orderItemUnit, OrderItemUnitInterface::class);

            // the following logic is a simple way of finding out whether the tax is included in the price or not
            // if the tax adjustment is neutral it means the tax is included in the price
            $tax = 0;
            $taxAdjustment = $orderItemUnit->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT)->first();
            if (false !== $taxAdjustment && $taxAdjustment->isNeutral()) {
                $tax = $taxAdjustment->getAmount();
            }

            $orderLine = new OrderLine(
                itemName: sprintf('%s (%s)', (string) $orderItem->getProductName(), (string) $orderItem->getProductName()),
                itemSku: $orderItem->getVariant()?->getCode(),
                quantity: $orderItem->getQuantity(),
                unitPriceExcludingVat: self::formatAmount($orderItem->getUnitPrice() - $tax),
                currencyCode: $order->getCurrencyCode(),
                unitWeight: null === $orderItem->getVariant()?->getWeight() ? null : (int) $orderItem->getVariant()?->getWeight(),
            );

            $this->eventDispatcher->dispatch(new MapOrderLineEvent($orderLine, $orderItem, $order));

            $salesOrder->orderLines[] = $orderLine;
        }

        $shippingAdjustments = $order->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT);
        foreach ($shippingAdjustments as $shippingAdjustment) {
            $orderLine = new OrderLine(
                lineType: OrderLine::LINE_TYPE_SHIPPING,
                itemName: $shippingAdjustment->getLabel(),
                quantity: 1,
                unitPriceExcludingVat: self::formatAmount($shippingAdjustment->getAmount()),
                currencyCode: $order->getCurrencyCode(),
            );

            $this->eventDispatcher->dispatch(new MapShippingOrderLineEvent($orderLine, $shippingAdjustment, $order));

            $salesOrder->orderLines[] = $orderLine;
        }

        $this->eventDispatcher->dispatch(new MapSalesOrderEvent($salesOrder, $order));
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
