<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Message\CommandHandler;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Client\Client;
use Setono\Shipmondo\Exception\ShipmondoException;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\DeleteOrderHandler;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class DeleteOrderHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderRepositoryInterface> */
    private ObjectProphecy $orderRepository;

    /** @var ObjectProphecy<HttpClientInterface> */
    private ObjectProphecy $httpClient;

    /** @var ObjectProphecy<LoggerInterface> */
    private ObjectProphecy $logger;

    private Psr17Factory $psr17;

    protected function setUp(): void
    {
        $this->orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        $this->httpClient = $this->prophesize(HttpClientInterface::class);
        $this->logger = $this->prophesize(LoggerInterface::class);
        $this->psr17 = new Psr17Factory();
    }

    /**
     * @test
     */
    public function it_deletes_the_sales_order_in_shipmondo_and_clears_the_id(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipmondoId()->willReturn(12345);
        $order->setShipmondoId(null)->shouldBeCalled();
        $this->orderRepository->find(42)->willReturn($order->reveal());

        $this->httpClient->sendRequest(Argument::that(
            static fn (RequestInterface $request): bool => 'DELETE' === $request->getMethod() &&
                str_contains((string) $request->getUri(), 'sales_orders/12345'),
        ))->willReturn($this->psr17->createResponse())->shouldBeCalled();

        self::assertTrue($this->handle(new DeleteOrder(42)));
    }

    /**
     * @test
     */
    public function it_clears_the_id_when_the_sales_order_was_already_deleted_in_shipmondo(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipmondoId()->willReturn(12345);
        $order->setShipmondoId(null)->shouldBeCalled();
        $this->orderRepository->find(42)->willReturn($order->reveal());

        $this->httpClient->sendRequest(Argument::any())->willReturn($this->psr17->createResponse(404));
        $this->logger->error(Argument::cetera())->shouldNotBeCalled();

        self::assertTrue($this->handle(new DeleteOrder(42)));
    }

    /**
     * @test
     */
    public function it_logs_instead_of_throwing_when_shipmondo_fails_so_the_cancellation_is_not_blocked(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipmondoId()->willReturn(12345);
        $order->getNumber()->willReturn('000000042');
        $order->setShipmondoId(Argument::any())->shouldNotBeCalled();
        $this->orderRepository->find(42)->willReturn($order->reveal());

        $this->httpClient->sendRequest(Argument::any())->willReturn($this->psr17->createResponse(500));
        $this->logger->error(Argument::type('string'), Argument::that(
            static fn (array $context): bool => 12345 === $context['shipmondoId'] &&
                '000000042' === $context['order'] &&
                $context['exception'] instanceof ShipmondoException,
        ))->shouldBeCalled();

        self::assertFalse($this->handle(new DeleteOrder(42)));
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_was_not_uploaded(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipmondoId()->willReturn(null);
        $order->setShipmondoId(Argument::any())->shouldNotBeCalled();
        $this->orderRepository->find(42)->willReturn($order->reveal());

        $this->httpClient->sendRequest(Argument::any())->shouldNotBeCalled();

        self::assertTrue($this->handle(new DeleteOrder(42)));
    }

    /**
     * @test
     */
    public function it_throws_when_the_order_does_not_exist(): void
    {
        $this->orderRepository->find(42)->willReturn(null);

        $this->expectException(UnrecoverableMessageHandlingException::class);

        $this->handle(new DeleteOrder(42));
    }

    /**
     * @test
     */
    public function it_throws_when_the_order_is_not_a_shipmondo_order(): void
    {
        $this->orderRepository->find(42)->willReturn($this->prophesize(BaseOrderInterface::class)->reveal());

        $this->expectException(\InvalidArgumentException::class);

        $this->handle(new DeleteOrder(42));
    }

    private function handle(DeleteOrder $message): bool
    {
        $client = new Client('user', 'key', true, $this->httpClient->reveal(), $this->psr17, $this->psr17);

        $handler = new DeleteOrderHandler($this->orderRepository->reveal(), $client, $this->logger->reveal());

        return $handler($message);
    }
}
