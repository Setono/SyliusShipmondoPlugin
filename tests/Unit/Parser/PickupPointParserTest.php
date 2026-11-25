<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Parser;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Parser\PickupPointParser;
use Tests\Setono\SyliusShipmondoPlugin\Support\PickupPoints;

final class PickupPointParserTest extends TestCase
{
    /**
     * @test
     */
    public function it_reads_the_json_of_a_pickup_point(): void
    {
        self::assertEquals(PickupPoints::create(), PickupPointParser::parse(PickupPoints::JSON));
    }

    /**
     * Shipments store the pickup point their customer chose, and the SDK reads only `zipCode` since 2.0.0-rc.3. A
     * pickup point stored before must still be read, or its order would be uploaded without it
     *
     * @test
     */
    public function it_reads_a_pickup_point_stored_before_the_zip_code_was_spelled_zip_code(): void
    {
        self::assertEquals(PickupPoints::create(), PickupPointParser::parse(PickupPoints::JSON_WITH_ZIPCODE));
    }

    /**
     * @test
     */
    public function it_reads_the_zip_code_spelled_zip_code_when_both_spellings_are_there(): void
    {
        $json = str_replace('"zipCode":"8000"', '"zipcode":"9999","zipCode":"8000"', PickupPoints::JSON);

        self::assertEquals(PickupPoints::create(), PickupPointParser::parse($json));
    }

    /**
     * A tampered checkout request or a broken pickup point picker
     *
     * @test
     *
     * @dataProvider unreadablePickupPoints
     */
    public function it_reads_json_that_is_not_a_pickup_points_as_no_pickup_point(string $json): void
    {
        self::assertNull(PickupPointParser::parse($json));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePickupPoints(): iterable
    {
        yield 'not JSON (#20)' => ['not json'];
        yield 'no JSON' => [''];
        yield 'JSON with a missing key (#37)' => ['{"id":"96285"}'];
        yield 'JSON with a value of the wrong type (#37)' => [str_replace('"id":"96285"', '"id":96285', PickupPoints::JSON)];
        yield 'JSON that is not an object' => ['"96285"'];
    }
}
