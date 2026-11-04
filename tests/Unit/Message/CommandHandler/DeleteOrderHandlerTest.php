<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Message\CommandHandler;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Exception\InternalServerErrorException;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\DeleteOrderHandler;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\Event\GuardEvent;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;

/**
 * Exercises the handler with the real Shipmondo SDK over a scripted HTTP transport, the real order state machine
 * (including its guard) and real orders
 */
final class DeleteOrderHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderRepositoryInterface> */
    private ObjectProphecy $orderRepository;

    private ScriptedHttpClient $shipmondo;

    private RecordingLogger $logger;

    /**
     * The order state machine's event dispatcher, for guards a store adds
     */
    private EventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        $this->shipmondo = new ScriptedHttpClient();
        $this->logger = new RecordingLogger();
        $this->eventDispatcher = new EventDispatcher();
    }

    /**
     * @test
     *
     * @dataProvider deletedSalesOrders
     */
    public function it_deletes_the_sales_order_in_shipmondo_and_cancels_the_cancelled_order(int $statusCode): void
    {
        $order = $this->uploadedOrder(cancelled: true);
        $this->shipmondo->respond($statusCode);

        self::assertTrue($this->handle(new DeleteOrder(42)));

        self::assertSame([OrderInterface::SHIPMONDO_STATE_CANCELLED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['DELETE /api/public/v3/sales_orders/12345'], $this->requests());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function deletedSalesOrders(): iterable
    {
        yield 'deleted' => [204];
        // the sales order was already deleted in Shipmondo, which is what we wanted
        yield 'already deleted (404)' => [404];
    }

    /**
     * @test
     */
    public function it_sends_an_order_that_is_not_cancelled_back_to_pending_to_be_uploaded_again(): void
    {
        $order = $this->uploadedOrder(cancelled: false);
        $this->shipmondo->respond(204);

        self::assertTrue($this->handle(new DeleteOrder(42)));

        self::assertSame([OrderInterface::SHIPMONDO_STATE_PENDING, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @test
     */
    public function it_does_not_throw_when_a_guard_a_store_added_holds_the_order_back(): void
    {
        $order = $this->uploadedOrder(cancelled: true);
        $this->shipmondo->respond(204);
        $this->eventDispatcher->addListener('workflow.setono_sylius_shipmondo__order.guard.cancel', static function (GuardEvent $event): void {
            $event->setBlocked(true);
        });

        self::assertTrue($this->handle(new DeleteOrder(42)));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $order->getShipmondoState());
    }

    /**
     * @test
     */
    public function it_logs_instead_of_throwing_when_shipmondo_fails_so_the_cancellation_is_not_blocked(): void
    {
        $order = $this->uploadedOrder(cancelled: true);
        $this->shipmondo->respond(500, ['error' => 'Internal Server Error']);

        self::assertFalse($this->handle(new DeleteOrder(42)));

        // the sales order still exists in Shipmondo, so the order stays uploaded and linked to it
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 12345], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertCount(1, $this->logger->records);
        $record = $this->logger->records[0];
        self::assertSame(LogLevel::ERROR, $record['level']);
        self::assertSame('Could not delete the Shipmondo sales order {shipmondoId} of the cancelled order {order}', $record['message']);
        self::assertSame([12345, '000000042'], [$record['context']['shipmondoId'], $record['context']['order']]);
        self::assertInstanceOf(InternalServerErrorException::class, $record['context']['exception']);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_was_not_uploaded(): void
    {
        $order = $this->order();
        $order->setState(OrderInterface::STATE_CANCELLED);

        self::assertTrue($this->handle(new DeleteOrder(42)));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $order->getShipmondoState());
        self::assertSame([], $this->requests());
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

    private function order(): Order
    {
        $order = new Order();
        $order->setNumber('000000042');
        $this->orderRepository->find(42)->willReturn($order);

        return $order;
    }

    /**
     * An order linked to the sales order 12345
     */
    private function uploadedOrder(bool $cancelled): Order
    {
        $order = $this->order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 12345]);
        if ($cancelled) {
            $order->setState(OrderInterface::STATE_CANCELLED);
        }

        return $order;
    }

    /**
     * @return list<string>
     */
    private function requests(): array
    {
        return array_map(
            static fn ($request): string => $request->getMethod() . ' ' . $request->getUri()->getPath(),
            $this->shipmondo->requests(),
        );
    }

    private function handle(DeleteOrder $message): bool
    {
        $handler = new DeleteOrderHandler(
            $this->orderRepository->reveal(),
            ShipmondoApi::client($this->shipmondo),
            OrderWorkflow::getWorkflow($this->eventDispatcher),
            $this->logger,
        );

        return $handler($message);
    }
}
