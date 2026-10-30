<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Message\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

final class UploadOrderTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_takes_the_id_and_version_of_an_order(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getId()->willReturn(42);
        $order->getVersion()->willReturn(3);

        $message = new UploadOrder($order->reveal());

        self::assertSame(42, $message->order);
        self::assertSame(3, $message->version);
    }

    /**
     * @test
     */
    public function it_accepts_an_integer_or_string_id(): void
    {
        $message = new UploadOrder(42);
        self::assertSame(42, $message->order);
        self::assertNull($message->version);

        $message = new UploadOrder('42', 7);
        self::assertSame('42', $message->order);
        self::assertSame(7, $message->version);
    }

    /**
     * @test
     */
    public function it_rejects_an_id_that_is_neither_an_integer_nor_a_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new UploadOrder(null);
    }
}
