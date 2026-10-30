<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Model;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

final class OrderTraitTest extends TestCase
{
    /**
     * @test
     */
    public function it_has_sensible_defaults(): void
    {
        $order = new Order();

        self::assertSame(1, $order->getVersion());
        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $order->getShipmondoState());
        self::assertNull($order->getShipmondoId());
    }

    /**
     * @test
     */
    public function it_sets_the_version(): void
    {
        $order = new Order();

        $order->setVersion(7);
        self::assertSame(7, $order->getVersion());

        $order->setVersion(null);
        self::assertSame(0, $order->getVersion());
    }

    /**
     * @test
     */
    public function it_sets_the_shipmondo_state(): void
    {
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO);

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $order->getShipmondoState());
    }

    /**
     * @test
     */
    public function it_sets_the_shipmondo_id(): void
    {
        $order = new Order();

        $order->setShipmondoId(12345);
        self::assertSame(12345, $order->getShipmondoId());

        $order->setShipmondoId(null);
        self::assertNull($order->getShipmondoId());
    }
}
