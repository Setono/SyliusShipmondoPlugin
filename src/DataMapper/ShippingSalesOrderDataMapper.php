<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrders\OrderLine;
use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
use Setono\SyliusShipmondoPlugin\Event\MapShippingOrderLineEvent;
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

            $taxAdjustments = $shipment->getAdjustments(AdjustmentInterface::TAX_ADJUSTMENT);
            Assert::lessThanEq($taxAdjustments->count(), 1);

            // Because of the way shipping and shipping taxes are done in Sylius, we need to do some gymnastics to calculate the correct unit price.
            // If the tax adjustment is neutral we know that the tax is included in the shipping adjustment's amount and hence we subtract the tax.
            // It the tax adjustment is not neutral we know that the tax is not included and hence we don't subtract the tax.
            // The tax is still the same though, and we need that to calculate the vat percentage
            $tax = 0;
            $unitPriceExcludingVat = $shippingAdjustment->getAmount();

            $taxAdjustment = $taxAdjustments->first();
            if (false !== $taxAdjustment) {
                $tax = $taxAdjustment->getAmount();

                if ($taxAdjustment->isNeutral()) {
                    $unitPriceExcludingVat -= $tax;
                }
            }

            $orderLine = new OrderLine(
                lineType: OrderLine::LINE_TYPE_SHIPPING,
                itemName: $shippingAdjustment->getLabel(),
                quantity: 1,
                unitPriceExcludingVat: self::formatAmount($unitPriceExcludingVat),
                vatPercent: (string) ($tax / $unitPriceExcludingVat),
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
