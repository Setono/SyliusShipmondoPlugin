<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Enum\OrderLineType;
use Setono\Shipmondo\Request\SalesOrder\OrderLine;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\Event\ShippingOrderLineMappedEvent;
use function Setono\SyliusShipmondoPlugin\formatAmount;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Webmozart\Assert\Assert;

final class ShippingSalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function __construct(private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    public function map(OrderInterface $order, SalesOrderRequest $salesOrder): void
    {
        foreach ($order->getShipments() as $shipment) {
            $shippingAdjustments = $shipment->getAdjustments(AdjustmentInterface::SHIPPING_ADJUSTMENT);
            if ($shippingAdjustments->isEmpty()) {
                continue;
            }

            // Sylius only adds one shipping adjustment per shipment
            Assert::count($shippingAdjustments, 1);

            $shippingAdjustment = $shippingAdjustments->first();
            Assert::isInstanceOf($shippingAdjustment, AdjustmentInterface::class);

            // Sylius adds the shipping tax as its own adjustment. A neutral one is included in the shipping adjustment's
            // amount, any other comes on top of it
            $taxAdjustments = $shipment->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT);
            Assert::lessThanEq($taxAdjustments->count(), 1);

            $taxAdjustment = $taxAdjustments->first();
            if (false === $taxAdjustment) {
                $unitPrice = AmountWithVat::excludingVat($shippingAdjustment->getAmount(), 0);
            } elseif ($taxAdjustment->isNeutral()) {
                $unitPrice = AmountWithVat::includingVat($shippingAdjustment->getAmount(), $taxAdjustment->getAmount());
            } else {
                $unitPrice = AmountWithVat::excludingVat($shippingAdjustment->getAmount(), $taxAdjustment->getAmount());
            }

            $orderLine = new OrderLine(
                lineType: OrderLineType::Shipping,
                itemName: $shippingAdjustment->getLabel(),
                quantity: 1,
                unitPriceExcludingVat: formatAmount($unitPrice->excludingVat),
                vatPercent: $unitPrice->vatPercent(),
                currencyCode: $order->getCurrencyCode(),
            );

            $this->eventDispatcher->dispatch(new ShippingOrderLineMappedEvent($orderLine, $shippingAdjustment, $order));

            $salesOrder->orderLines[] = $orderLine;
        }
    }
}
