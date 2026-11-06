<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DataMapper;

use function Setono\SyliusShipmondoPlugin\formatAmount;

/**
 * An amount and the VAT on it, as a sales order states them: the amount excluding VAT, the VAT amount, the amount
 * including VAT and the VAT percent, formatted as Shipmondo reads them. The data mappers that send an amount work it out
 * by this one rule, so a free item, a shipping method that costs nothing or a free order has a VAT percent of 0 rather
 * than a division by zero.
 */
final class AmountWithVat
{
    /**
     * @param int $excludingVat in minor units, as Sylius keeps amounts
     * @param int $vat in minor units
     */
    private function __construct(private readonly int $excludingVat, private readonly int $vat)
    {
    }

    /**
     * An amount the VAT is part of, e.g. an order item unit's total or the order's total
     */
    public static function fromAmountIncludingVat(int $amount, int $vat): self
    {
        return new self($amount - $vat, $vat);
    }

    /**
     * An amount the VAT comes on top of, e.g. a shipping charge whose tax isn't included in it
     */
    public static function fromAmountExcludingVat(int $amount, int $vat): self
    {
        return new self($amount, $vat);
    }

    public function amountExcludingVat(): string
    {
        return formatAmount($this->excludingVat);
    }

    public function vatAmount(): string
    {
        return formatAmount($this->vat);
    }

    public function amountIncludingVat(): string
    {
        return formatAmount($this->excludingVat + $this->vat);
    }

    /**
     * The VAT as a share of the amount excluding VAT, e.g. '0.25' for 25 %, which is what Shipmondo's vat_percent holds.
     * It is '0' when there is no amount excluding VAT to share the VAT out on
     */
    public function vatPercent(): string
    {
        if ($this->excludingVat <= 0) {
            return '0';
        }

        return (string) ($this->vat / $this->excludingVat);
    }
}
