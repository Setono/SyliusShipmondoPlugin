<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Message\CommandHandler;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\UploadOrderHandler;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\UploadOutcome;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class UploadOrderHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderRepositoryInterface> */
    private ObjectProphecy $orderRepository;

    /** @var ObjectProphecy<OrderUploaderInterface> */
    private ObjectProphecy $orderUploader;

    protected function setUp(): void
    {
        $this->orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        $this->orderUploader = $this->prophesize(OrderUploaderInterface::class);
    }

    /**
     * @test
     */
    public function it_uploads_the_order_as_it_was_when_the_command_was_dispatched_and_returns_the_outcome(): void
    {
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderRepository->find(42)->willReturn($order);
        $this->orderUploader->uploadOrder($order, 6)->willReturn(UploadOutcome::Rejected);

        self::assertSame(UploadOutcome::Rejected, $this->handle(new UploadOrder(42, 6)));
    }

    /**
     * @test
     */
    public function it_throws_when_the_order_does_not_exist(): void
    {
        $this->orderRepository->find(42)->willReturn(null);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle(new UploadOrder(42));
    }

    /**
     * @test
     */
    public function it_throws_when_the_order_is_not_a_shipmondo_order(): void
    {
        $this->orderRepository->find(42)->willReturn($this->prophesize(BaseOrderInterface::class)->reveal());

        $this->expectException(\InvalidArgumentException::class);

        $this->handle(new UploadOrder(42));
    }

    private function handle(UploadOrder $message): UploadOutcome
    {
        return (new UploadOrderHandler($this->orderRepository->reveal(), $this->orderUploader->reveal()))($message);
    }
}
