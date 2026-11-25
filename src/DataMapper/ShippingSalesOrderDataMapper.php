<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Enum\OrderLineType;
use Setono\Shipmondo\Request\SalesOrder\OrderLine;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\Event\ShippingOrderLineMappedEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\AdjustmentInterface;
use Webmozart\Assert\Assert;

final readonly class ShippingSalesOrderDataMapper implements SalesOrderDataMapperInterface
{
    public function __construct(private EventDispatcherInterface $eventDispatcher)
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

            // The customer pays for the shipping after its shipping promotions, which Sylius adds to the shipment as
            // their own adjustments
            $shippingAfterPromotions = $shippingAdjustment->getAmount() + $shipment->getAdjustmentsTotal(AdjustmentInterface::ORDER_SHIPPING_PROMOTION_ADJUSTMENT);

            // Sylius taxes that amount and adds the tax as its own adjustment. A neutral one is included in the amount,
            // any other comes on top of it
            $taxAdjustments = $shipment->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT);
            Assert::lessThanEq($taxAdjustments->count(), 1);

            $taxAdjustment = $taxAdjustments->first();
            $tax = false === $taxAdjustment ? 0 : $taxAdjustment->getAmount();
            $unitPrice = false !== $taxAdjustment && $taxAdjustment->isNeutral()
                ? AmountWithVat::fromAmountIncludingVat($shippingAfterPromotions, $tax)
                : AmountWithVat::fromAmountExcludingVat($shippingAfterPromotions, $tax)
            ;

            $orderLine = new OrderLine(
                lineType: OrderLineType::Shipping,
                itemName: $shippingAdjustment->getLabel(),
                quantity: 1,
                unitPriceExcludingVat: $unitPrice->amountExcludingVat(),
                vatPercent: $unitPrice->vatPercent(),
                currencyCode: $order->getCurrencyCode(),
            );

            $this->eventDispatcher->dispatch(new ShippingOrderLineMappedEvent($orderLine, $shippingAdjustment, $order));

            $salesOrder->orderLines[] = $orderLine;
        }
    }
}
