<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Provider\OrderIdProvider;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

final class OrderIdProviderTest extends TestCase
{
    /**
     * @test
     */
    public function it_gives_the_order_number_as_the_order_id(): void
    {
        self::assertSame('000001234', (new OrderIdProvider())->getOrderId(self::order('000001234')));
    }

    /**
     * Shops that share a Shipmondo account number their orders the same way, so each sets a prefix of its own
     *
     * @test
     */
    public function it_puts_the_order_id_prefix_before_the_order_number(): void
    {
        self::assertSame('WEB-000001234', (new OrderIdProvider('WEB-'))->getOrderId(self::order('000001234')));
    }

    private static function order(string $number): Order
    {
        $order = new Order();
        $order->setNumber($number);

        return $order;
    }
}
