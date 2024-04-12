<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrders\Address;
use Setono\Shipmondo\Request\SalesOrders\OrderLine;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\Event\MapShippingOrderLineEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;

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
    }

    private static function formatAmount(int $amount): string
    {
        return (string) round($amount / 100, 2);
    }
}
