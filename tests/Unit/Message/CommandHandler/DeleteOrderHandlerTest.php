<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Message\CommandHandler;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Shipmondo\Exception\InternalServerErrorException;
use Setono\Shipmondo\Exception\TooManyRequestsException;
use Setono\Shipmondo\Exception\UnauthorizedException;
use Setono\Shipmondo\Exception\ValidationException;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCanceller;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\DeleteOrderHandler;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Orders;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;

/**
 * Exercises the handler with the real Shipmondo SDK over a scripted HTTP transport, the real order state machine
 * (including its guard), the real canceller and real orders
 */
final class DeleteOrderHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderRepositoryInterface> */
    private ObjectProphecy $orderRepository;

    private ScriptedHttpClient $shipmondo;

    /**
     * The order state machine's event dispatcher, for guards a store adds
     */
    private EventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        $this->shipmondo = new ScriptedHttpClient();
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
        self::assertSame(['DELETE sales_orders/12345'], $this->shipmondo->requestLines());
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
        $this->eventDispatcher->addListener('workflow.setono_sylius_shipmondo__order.guard.reset', static function (GuardEvent $event): void {
            $event->setBlocked(true);
        });

        self::assertTrue($this->handle(new DeleteOrder(42)));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $order->getShipmondoState());
        self::assertSame(['DELETE sales_orders/12345'], $this->shipmondo->requestLines());
    }

    /**
     * Messenger retries a failure expected to pass, with the transport's retry strategy, when a worker handles the
     * command; handled synchronously, AbsorbDeleteOrderFailureMiddleware absorbs it
     *
     * @test
     *
     * @dataProvider temporaryFailures
     *
     * @param class-string<\Throwable> $exception
     */
    public function it_throws_a_failure_expected_to_pass_as_it_is(int $statusCode, string $exception): void
    {
        $order = $this->uploadedOrder(cancelled: true);
        $this->shipmondo->respond($statusCode, ['error' => 'Try again']);

        try {
            $this->handle(new DeleteOrder(42));
            self::fail('The failure was not thrown');
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
        }

        // the sales order still exists in Shipmondo, so the order stays uploaded and linked to it
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 12345], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function temporaryFailures(): iterable
    {
        yield 'server error' => [500, InternalServerErrorException::class];
        yield 'rate limited' => [429, TooManyRequestsException::class];
    }

    /**
     * Trying again won't change these, so Messenger sends the command straight to the failure transport
     *
     * @test
     *
     * @dataProvider lastingFailures
     *
     * @param class-string<\Throwable> $exception
     */
    public function it_throws_any_other_failure_as_unrecoverable(int $statusCode, string $exception): void
    {
        $order = $this->uploadedOrder(cancelled: true);
        $this->shipmondo->respond($statusCode, ['error' => 'No']);

        try {
            $this->handle(new DeleteOrder(42));
            self::fail('The failure was not thrown');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertSame('Shipmondo did not delete the sales order 12345 of the order 000000042, and trying again will not change that', $e->getMessage());
            self::assertInstanceOf($exception, $e->getPrevious());
        }

        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 12345], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @return iterable<string, array{int, class-string<\Throwable>}>
     */
    public static function lastingFailures(): iterable
    {
        yield 'credentials failure' => [401, UnauthorizedException::class];
        yield 'rejected' => [422, ValidationException::class];
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_was_not_uploaded(): void
    {
        $order = $this->order(Orders::create(state: OrderInterface::STATE_CANCELLED, number: '000000042'));

        self::assertTrue($this->handle(new DeleteOrder(42)));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $order->getShipmondoState());
        self::assertSame([], $this->shipmondo->requestLines());
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

    /**
     * Makes the order the one with the id 42
     */
    private function order(Order $order): Order
    {
        $this->orderRepository->find(42)->willReturn($order);

        return $order;
    }

    /**
     * An order linked to the sales order 12345
     */
    private function uploadedOrder(bool $cancelled): Order
    {
        return $this->order(Orders::create(
            OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO,
            12345,
            $cancelled ? OrderInterface::STATE_CANCELLED : OrderInterface::STATE_NEW,
            number: '000000042',
        ));
    }

    private function handle(DeleteOrder $message): bool
    {
        $orderWorkflow = OrderWorkflow::getWorkflow($this->eventDispatcher);

        // the order is pending by the time the canceller gets it, so it has no sales order to delete
        $commandBus = $this->prophesize(MessageBusInterface::class);
        $commandBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $handler = new DeleteOrderHandler(
            $this->orderRepository->reveal(),
            ShipmondoApi::client($this->shipmondo),
            $orderWorkflow,
            new OrderCanceller($commandBus->reveal(), $orderWorkflow),
        );

        return $handler($message);
    }
}
