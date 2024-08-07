<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrders\OrderLine;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
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

    public function map(OrderInterface $order, SalesOrder $salesOrder): void
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

            // Because of the way shipping and shipping taxes are done in Sylius, we need to do some gymnastics to calculate the correct unit price.
            // If the tax adjustment is neutral we know that the tax is included in the shipping adjustment's amount and hence we subtract the tax.
            // If the tax adjustment is not neutral we know that the tax is not included and hence we don't subtract the tax.
            // The tax is still the same though, and we need that to calculate the vat percentage
            $vatPercentage = 0.0;
            $unitPriceExcludingVat = $shippingAdjustment->getAmount();

            if ($unitPriceExcludingVat > 0) {
                $taxAdjustments = $shipment->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT);
                Assert::lessThanEq($taxAdjustments->count(), 1);

                $tax = 0;

                $taxAdjustment = $taxAdjustments->first();
                if (false !== $taxAdjustment) {
                    $tax = $taxAdjustment->getAmount();

                    if ($taxAdjustment->isNeutral()) {
                        $unitPriceExcludingVat -= $tax;
                    }
                }

                $vatPercentage = $tax / $unitPriceExcludingVat;
            }

            $orderLine = new OrderLine(
                lineType: OrderLine::LINE_TYPE_SHIPPING,
                itemName: $shippingAdjustment->getLabel(),
                quantity: 1,
                unitPriceExcludingVat: formatAmount($unitPriceExcludingVat),
                vatPercent: (string) $vatPercentage,
                currencyCode: $order->getCurrencyCode(),
            );

            $this->eventDispatcher->dispatch(new ShippingOrderLineMappedEvent($orderLine, $shippingAdjustment, $order));

            $salesOrder->orderLines[] = $orderLine;
        }
    }
}
