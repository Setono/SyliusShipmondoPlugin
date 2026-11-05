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
