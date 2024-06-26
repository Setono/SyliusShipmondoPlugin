<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrders\OrderLine;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\Event\OrderLineMappedEvent;
use function Setono\SyliusShipmondoPlugin\formatAmount;
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

            $itemName = (string) $orderItem->getProductName();
            if ($orderItem->getVariantName() !== null) {
                $itemName .= sprintf(' (%s)', (string) $orderItem->getVariantName());
            }

            $orderLine = new OrderLine(
                itemName: $itemName,
                itemSku: $orderItem->getVariant()?->getCode(),
                quantity: $orderItem->getQuantity(),
                unitPriceExcludingVat: formatAmount($unitPriceExcludingVat),
                vatPercent: (string) ($orderItemUnit->getTaxTotal() / $unitPriceExcludingVat),
                currencyCode: $order->getCurrencyCode(),
                unitWeight: null === $orderItem->getVariant()?->getWeight() ? null : (int) $orderItem->getVariant()?->getWeight(),
            );

            $this->eventDispatcher->dispatch(new OrderLineMappedEvent($orderLine, $orderItem, $order));

            $salesOrder->orderLines[] = $orderLine;
        }
    }
}
