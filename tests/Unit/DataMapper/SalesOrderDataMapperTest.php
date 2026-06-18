<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use PHPUnit\Framework\TestCase;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapper;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Customer;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

final class SalesOrderDataMapperTest extends TestCase
{
    /**
     * @test
     */
    public function it_maps_basic_order_data_and_addresses(): void
    {
        $order = new Order();
        $order->setNumber('000000123');
        $order->setNotes('Leave at the door');

        $checkoutCompletedAt = new \DateTime('2026-01-02 03:04:05');
        $order->setCheckoutCompletedAt($checkoutCompletedAt);

        $customer = new Customer();
        $customer->setEmail('john@example.com');
        $order->setCustomer($customer);

        $shippingAddress = new Address();
        $shippingAddress->setFirstName('John');
        $shippingAddress->setLastName('Doe');
        $shippingAddress->setStreet('Shipping St 1');
        $shippingAddress->setPostcode('1000');
        $shippingAddress->setCity('Copenhagen');
        $shippingAddress->setCountryCode('DK');
        $shippingAddress->setPhoneNumber('11111111');
        $order->setShippingAddress($shippingAddress);

        $billingAddress = new Address();
        $billingAddress->setFirstName('Jane');
        $billingAddress->setLastName('Roe');
        $billingAddress->setStreet('Billing Rd 2');
        $billingAddress->setPostcode('2000');
        $billingAddress->setCity('Aarhus');
        $billingAddress->setCountryCode('DK');
        $billingAddress->setPhoneNumber('22222222');
        $order->setBillingAddress($billingAddress);

        $salesOrder = new SalesOrderRequest();

        $mapper = new SalesOrderDataMapper();
        $mapper->map($order, $salesOrder);

        self::assertSame('000000123', $salesOrder->orderId);
        self::assertSame($checkoutCompletedAt, $salesOrder->orderedAt);
        self::assertSame('Sylius', $salesOrder->sourceName);
        self::assertSame('Leave at the door', $salesOrder->orderNote);

        self::assertNotNull($salesOrder->shipTo);
        self::assertSame('John Doe', $salesOrder->shipTo->name);
        self::assertSame('Shipping St 1', $salesOrder->shipTo->address1);
        self::assertSame('1000', $salesOrder->shipTo->zipcode);
        self::assertSame('Copenhagen', $salesOrder->shipTo->city);
        self::assertSame('DK', $salesOrder->shipTo->countryCode);
        self::assertSame('john@example.com', $salesOrder->shipTo->email);
        self::assertSame('11111111', $salesOrder->shipTo->mobile);

        self::assertNotNull($salesOrder->billTo);
        self::assertSame('Jane Roe', $salesOrder->billTo->name);
        self::assertSame('Billing Rd 2', $salesOrder->billTo->address1);
        self::assertSame('2000', $salesOrder->billTo->zipcode);
        self::assertSame('Aarhus', $salesOrder->billTo->city);
        self::assertSame('DK', $salesOrder->billTo->countryCode);
        self::assertSame('john@example.com', $salesOrder->billTo->email);
        self::assertSame('22222222', $salesOrder->billTo->mobile);
    }

    /**
     * @test
     */
    public function it_maps_an_empty_order_id_when_the_order_has_no_number(): void
    {
        $order = new Order();

        $salesOrder = new SalesOrderRequest();

        $mapper = new SalesOrderDataMapper();
        $mapper->map($order, $salesOrder);

        // getNumber() is null, and the mapper casts it to a string
        self::assertSame('', $salesOrder->orderId);

        self::assertNotNull($salesOrder->shipTo);
        self::assertNull($salesOrder->shipTo->name);
        self::assertNull($salesOrder->shipTo->email);
    }
}
