<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\CommandHandler;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\DTO\Model\Address;
use Setono\Shipmondo\DTO\Model\SalesOrder;
use Setono\SyliusShipmondoPlugin\Message\Command\DispatchOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Webmozart\Assert\Assert;

final class DispatchOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ClientInterface $shipmondoClient,
    ) {
    }

    public function __invoke(DispatchOrder $message): void
    {
        /** @var OrderInterface|null $order */
        $order = $this->orderRepository->find($message->order);
        if (null === $order) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s does not exist', (string) $message->order));
        }

        Assert::isInstanceOf($order, OrderInterface::class);

        if (null !== $message->version && $order->getVersion() !== $message->version) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s has been updated since it was dispatched', (string) $message->order));
        }

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
                paymentMethod: $order->getPayments()->first()->getMethod()?->getName(),
            ),
        );

        foreach ($order->getItems() as $orderItem) {
            // todo send an event here to allow developers to modify the order line

            $salesOrder->orderLines[] = new SalesOrder\OrderLine(
                itemName: sprintf('%s (%s)', (string) $orderItem->getProductName(), (string) $orderItem->getProductName()),
                itemSku: $orderItem->getVariant()?->getCode(),
                quantity: $orderItem->getQuantity(),
                unitPriceExcludingVat: self::formatAmount($orderItem->getUnitPrice() - $orderItem->getUnits()->first()->getTaxTotal()),
                currencyCode: $order->getCurrencyCode(),
            );
        }

        $shippingAdjustments = $order->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT);
        foreach ($shippingAdjustments as $shippingAdjustment) {
            // todo send an event here to allow developers to modify the order / shipping line

            $salesOrder->orderLines[] = new SalesOrder\OrderLine(
                lineType: SalesOrder\OrderLine::LINE_TYPE_SHIPPING,
                itemName: $shippingAdjustment->getLabel(),
                quantity: 1,
                unitPriceExcludingVat: self::formatAmount($shippingAdjustment->getAmount()),
                currencyCode: $order->getCurrencyCode(),
            );
        }

        // todo send an event here to allow developers to modify the sales order

        $this->shipmondoClient->salesOrders()->create($salesOrder);
    }

    private static function formatAmount(int $amount): string
    {
        return (string) round($amount / 100, 2);
    }
}
