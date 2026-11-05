<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Doctrine\Type\PickupPointType;
use Tests\Setono\SyliusShipmondoPlugin\Support\PickupPoints;

final class PickupPointTypeTest extends TestCase
{
    /**
     * @test
     */
    public function it_stores_a_pickup_point_as_its_json(): void
    {
        self::assertSame(PickupPoints::JSON, self::type()->convertToDatabaseValue(PickupPoints::create(), self::platform()));
    }

    /**
     * @test
     */
    public function it_stores_no_pickup_point_as_null(): void
    {
        self::assertNull(self::type()->convertToDatabaseValue(null, self::platform()));
    }

    /**
     * @test
     */
    public function it_does_not_store_anything_but_a_pickup_point(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Expected one of the following types: null, Setono\\Shipmondo\\Response\\PickupPoint\\PickupPoint');

        self::type()->convertToDatabaseValue(['id' => '96285'], self::platform());
    }

    /**
     * @test
     */
    public function it_reads_a_stored_pickup_point(): void
    {
        self::assertEquals(PickupPoints::create(), self::type()->convertToPHPValue(PickupPoints::JSON, self::platform()));
    }

    /**
     * @test
     */
    public function it_reads_a_pickup_point_stored_with_the_raw_shipmondo_response(): void
    {
        // what the checkout stored before pickup points were stored by this type
        $json = substr(PickupPoints::JSON, 0, -1) . ',"raw":{"id":"96285","company_name":"GLS"}}';

        self::assertEquals(PickupPoints::create(), self::type()->convertToPHPValue($json, self::platform()));
    }

    /**
     * @test
     *
     * @dataProvider noPickupPoint
     */
    public function it_reads_a_value_without_a_pickup_point_as_null(?string $value): void
    {
        self::assertNull(self::type()->convertToPHPValue($value, self::platform()));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function noPickupPoint(): iterable
    {
        yield 'null' => [null];
        yield 'an empty string' => [''];
    }

    /**
     * Only a tampered checkout request could have stored one, before the checkout parsed pickup points. The order
     * must still load everywhere. PickupPointParserTest covers what can't be read
     *
     * @test
     */
    public function it_reads_a_stored_pickup_point_it_cannot_read_as_null(): void
    {
        self::assertNull(self::type()->convertToPHPValue('{"id":"96285","name":"Pakkeshop Netto Vestergade"}', self::platform()));
    }

    /**
     * @test
     */
    public function it_reads_a_pickup_point_the_driver_reads_as_a_stream(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, PickupPoints::JSON);
        rewind($stream);

        self::assertEquals(PickupPoints::create(), self::type()->convertToPHPValue($stream, self::platform()));
    }

    /**
     * @test
     */
    public function it_is_a_json_column(): void
    {
        self::assertSame((new JsonType())->getSQLDeclaration([], self::platform()), self::type()->getSQLDeclaration([], self::platform()));
    }

    /**
     * The comment lets the schema tool tell the column from a plain JSON column, so it doesn't change the column
     * on every run (DBAL 2, and DBAL 3 on platforms without inline column comments)
     *
     * @test
     */
    public function it_is_named_in_the_column_comment(): void
    {
        self::assertSame('setono_sylius_shipmondo_pickup_point', self::type()->getName());
        self::assertTrue(self::type()->requiresSQLCommentHint(self::platform()));
        self::assertTrue(self::type()->requiresSQLCommentHint(new PostgreSQLPlatform()));
    }

    private static function type(): PickupPointType
    {
        return new PickupPointType();
    }

    private static function platform(): AbstractPlatform
    {
        return new MySQLPlatform();
    }
}
