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
        $amount = AmountWithVat::fromAmountIncludingVat(12550, 2510);

        self::assertSame('100.4', $amount->amountExcludingVat());
        self::assertSame('25.1', $amount->vatAmount());
        self::assertSame('125.5', $amount->amountIncludingVat());
        self::assertSame('0.25', $amount->vatPercent());
    }

    /**
     * @test
     */
    public function it_puts_the_vat_on_top_of_an_amount_excluding_it(): void
    {
        $amount = AmountWithVat::fromAmountExcludingVat(10000, 2000);

        self::assertSame('100', $amount->amountExcludingVat());
        self::assertSame('20', $amount->vatAmount());
        self::assertSame('120', $amount->amountIncludingVat());
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
        yield 'a free item' => [AmountWithVat::fromAmountIncludingVat(0, 0)];
        yield 'a shipping method that costs nothing' => [AmountWithVat::fromAmountExcludingVat(0, 0)];
        // e.g. a discount larger than the price
        yield 'a negative amount' => [AmountWithVat::fromAmountExcludingVat(-100, 0)];
    }

    /**
     * @test
     */
    public function its_vat_percent_is_0_without_vat(): void
    {
        self::assertSame('0', AmountWithVat::fromAmountIncludingVat(10000, 0)->vatPercent());
    }
}
