<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolver;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;

final class OrderResolverTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderRepositoryInterface> */
    private ObjectProphecy $orderRepository;

    protected function setUp(): void
    {
        $this->orderRepository = $this->prophesize(OrderRepositoryInterface::class);
    }

    /**
     * @test
     */
    public function it_resolves_by_shipmondo_id(): void
    {
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderRepository->findOneBy(['shipmondoId' => 37])->willReturn($order);

        self::assertSame($order, $this->resolver()->resolveFromPayload(['id' => 37, 'order_id' => '000000001']));
    }

    /**
     * @test
     */
    public function it_falls_back_to_the_order_number(): void
    {
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderRepository->findOneBy(['shipmondoId' => 37])->willReturn(null);
        $this->orderRepository->findOneBy(['number' => '000000001'])->willReturn($order);

        self::assertSame($order, $this->resolver()->resolveFromPayload(['id' => 37, 'order_id' => '000000001']));
    }

    /**
     * @test
     */
    public function it_resolves_by_number_when_there_is_no_shipmondo_id(): void
    {
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderRepository->findOneBy(['number' => '000000001'])->willReturn($order);

        self::assertSame($order, $this->resolver()->resolveFromPayload(['order_id' => '000000001']));
    }

    /**
     * @test
     */
    public function it_returns_null_when_nothing_matches(): void
    {
        $this->orderRepository->findOneBy(['shipmondoId' => 37])->willReturn(null);
        $this->orderRepository->findOneBy(['number' => 'unknown'])->willReturn(null);

        self::assertNull($this->resolver()->resolveFromPayload(['id' => 37, 'order_id' => 'unknown']));
    }

    /**
     * @test
     */
    public function it_returns_null_without_identifiers(): void
    {
        $this->orderRepository->findOneBy(Argument::any())->shouldNotBeCalled();

        self::assertNull($this->resolver()->resolveFromPayload([]));
    }

    private function resolver(): OrderResolver
    {
        return new OrderResolver($this->orderRepository->reveal());
    }
}
