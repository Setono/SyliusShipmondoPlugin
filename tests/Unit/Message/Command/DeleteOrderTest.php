<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Message\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

final class DeleteOrderTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_takes_the_id_of_an_order(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getId()->willReturn(42);

        self::assertSame(42, (new DeleteOrder($order->reveal()))->order);
    }

    /**
     * @test
     */
    public function it_accepts_an_integer_or_string_id(): void
    {
        self::assertSame(42, (new DeleteOrder(42))->order);
        self::assertSame('42', (new DeleteOrder('42'))->order);
    }

    /**
     * @test
     */
    public function it_rejects_an_id_that_is_neither_an_integer_nor_a_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeleteOrder(null);
    }
}
