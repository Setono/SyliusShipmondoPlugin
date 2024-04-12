<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrders\OrderLine;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\Event\OrderLineMappedEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemUnitInterface;
use Webmozart\Assert\Assert;

final class OrderLinesSalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function map(OrderInterface $order, SalesOrder $salesOrder): void
    {
        foreach ($order->getItems() as $orderItem) {
            /** @var OrderItemUnitInterface|false $orderItemUnit */
            $orderItemUnit = $orderItem->getUnits()->first();
            Assert::isInstanceOf($orderItemUnit, OrderItemUnitInterface::class);

            $unitPriceExcludingVat = $orderItemUnit->getTotal() - $orderItemUnit->getTaxTotal();

            $orderLine = new OrderLine(
                itemName: sprintf('%s (%s)', (string) $orderItem->getProductName(), (string) $orderItem->getVariantName()),
                itemSku: $orderItem->getVariant()?->getCode(),
                quantity: $orderItem->getQuantity(),
                unitPriceExcludingVat: self::formatAmount($unitPriceExcludingVat),
                vatPercent: (string) ($orderItemUnit->getTaxTotal() / $unitPriceExcludingVat),
                currencyCode: $order->getCurrencyCode(),
                unitWeight: null === $orderItem->getVariant()?->getWeight() ? null : (int) $orderItem->getVariant()?->getWeight(),
            );

            $this->eventDispatcher->dispatch(new OrderLineMappedEvent($orderLine, $orderItem, $order));

            $salesOrder->orderLines[] = $orderLine;
        }
    }

    private static function formatAmount(int $amount): string
    {
        return (string) round($amount / 100, 2);
    }
}
