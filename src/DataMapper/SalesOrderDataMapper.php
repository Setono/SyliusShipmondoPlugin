<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Setono\Shipmondo\Request\SalesOrders\Address;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

final class SalesOrderDataMapper implements SalesOrderDataMapperInterface
{
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
    }
}
