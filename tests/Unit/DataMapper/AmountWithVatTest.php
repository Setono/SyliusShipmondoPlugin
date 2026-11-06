<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\DataMapper\AmountWithVat;

final class AmountWithVatTest extends TestCase
{
    /**
     * @test
     */
    public function it_takes_the_vat_out_of_an_amount_including_it(): void
    {
        $amount = AmountWithVat::includingVat(12500, 2500);

        self::assertSame(10000, $amount->excludingVat);
        self::assertSame(2500, $amount->vat);
        self::assertSame('0.25', $amount->vatPercent());
    }

    /**
     * @test
     */
    public function it_keeps_an_amount_the_vat_comes_on_top_of(): void
    {
        $amount = AmountWithVat::excludingVat(10000, 2000);

        self::assertSame(10000, $amount->excludingVat);
        self::assertSame(2000, $amount->vat);
        self::assertSame('0.2', $amount->vatPercent());
    }

    /**
     * @test
     *
     * @dataProvider amountsWithoutAnAmountExcludingVat
     */
    public function its_vat_percent_is_0_without_an_amount_excluding_vat(AmountWithVat $amount): void
    {
        self::assertSame('0', $amount->vatPercent());
    }

    /**
     * @return iterable<string, array{AmountWithVat}>
     */
    public static function amountsWithoutAnAmountExcludingVat(): iterable
    {
        yield 'a free item' => [AmountWithVat::includingVat(0, 0)];
        yield 'free shipping with VAT on top' => [AmountWithVat::excludingVat(0, 0)];
        // e.g. a discount larger than the price
        yield 'a negative amount' => [AmountWithVat::excludingVat(-100, 0)];
    }

    /**
     * @test
     */
    public function its_vat_percent_is_0_without_vat(): void
    {
        self::assertSame('0', AmountWithVat::includingVat(10000, 0)->vatPercent());
    }
}
