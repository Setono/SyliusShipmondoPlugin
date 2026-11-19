<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Registrar;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookHost;

final class WebhookHostTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider reachableHosts
     */
    public function shipmondo_can_reach_a_hostname(string $host): void
    {
        self::assertTrue(WebhookHost::isReachable($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reachableHosts(): iterable
    {
        yield 'a hostname' => ['shop.example.com'];
        yield 'a hostname in capitals' => ['Shop.Example.COM'];
        yield 'a hostname, fully qualified' => ['shop.example.com.'];
        yield 'a name that only starts like a loopback address' => ['127.example.com'];
        yield 'a name that only starts with localhost' => ['localhost.example.com'];
        yield 'a name that only ends with localhost' => ['mylocalhost'];
        yield 'an IPv4 address that is not a loopback address' => ['203.0.113.10'];
    }

    /**
     * @test
     *
     * @dataProvider unreachableHosts
     */
    public function shipmondo_cannot_reach_the_shops_own_machine_nor_what_is_not_a_hostname(string $host): void
    {
        self::assertFalse(WebhookHost::isReachable($host));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreachableHosts(): iterable
    {
        yield 'empty' => [''];
        yield 'localhost' => ['localhost'];
        yield 'localhost in capitals' => ['LocalHost'];
        yield 'localhost, fully qualified' => ['localhost.'];
        yield 'a name under localhost' => ['shop.localhost'];
        yield 'a name under localhost, fully qualified' => ['shop.localhost.'];
        yield 'a loopback address' => ['127.0.0.1'];
        yield 'another loopback address' => ['127.1.2.3'];
        yield 'an IPv6 loopback address' => ['[::1]'];
        yield 'with a port' => ['shop.example.com:8000'];
        yield 'a URL' => ['https://shop.example.com'];
    }
}
