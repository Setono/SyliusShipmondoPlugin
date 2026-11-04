<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Canceller;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Canceller\Cancellation;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCanceller;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Workflow\Event\GuardEvent;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Orders;

/**
 * Exercises the canceller with the real order state machine (including its guard) and real orders
 */
final class OrderCancellerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $commandBus;

    /**
     * The order state machine's event dispatcher, for guards a store adds
     */
    private EventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->commandBus = $this->prophesize(MessageBusInterface::class);
        $this->eventDispatcher = new EventDispatcher();
    }

    /**
     * @test
     *
     * @dataProvider statesWithoutASalesOrder
     */
    public function it_cancels_an_order_that_shipmondo_has_no_sales_order_for_right_away(string $shipmondoState): void
    {
        $order = self::cancelledOrder($shipmondoState);
        $this->commandBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        self::assertSame(Cancellation::Cancelled, $this->canceller()->cancel($order));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_CANCELLED, $order->getShipmondoState());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesWithoutASalesOrder(): iterable
    {
        yield 'pending' => [OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'failed' => [OrderInterface::SHIPMONDO_STATE_FAILED];
        yield 'already cancelled' => [OrderInterface::SHIPMONDO_STATE_CANCELLED];
    }

    /**
     * @test
     *
     * @dataProvider deletions
     */
    public function it_deletes_the_sales_order_of_an_uploaded_order_once_the_command_being_handled_has_finished(
        ?HandledStamp $handled,
        Cancellation $cancellation,
    ): void {
        $order = self::cancelledOrder(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO);
        $this->commandBus->dispatch(
            Argument::that(static fn (object $message): bool => $message instanceof DeleteOrder && 42 === $message->order),
            [new DispatchAfterCurrentBusStamp()],
        )->willReturn(new Envelope(new \stdClass(), null === $handled ? [] : [$handled]))->shouldBeCalledOnce();

        self::assertSame($cancellation, $this->canceller()->cancel($order));

        // DeleteOrder makes the order cancelled once the sales order is gone
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @return iterable<string, array{HandledStamp|null, Cancellation}>
     */
    public static function deletions(): iterable
    {
        yield 'deleted' => [new HandledStamp(true, 'DeleteOrderHandler::__invoke'), Cancellation::SalesOrderDeleted];
        yield 'could not be deleted' => [new HandledStamp(false, 'DeleteOrderHandler::__invoke'), Cancellation::SalesOrderNotDeleted];
        yield 'deleted later, by an asynchronous transport or after the command being handled' => [null, Cancellation::SalesOrderDeletedLater];
    }

    /**
     * @test
     */
    public function it_leaves_an_order_in_uploading_to_its_upload(): void
    {
        $order = self::cancelledOrder(OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO);
        $this->commandBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        self::assertSame(Cancellation::LeftToUpload, $this->canceller()->cancel($order));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO, $order->getShipmondoState());
    }

    /**
     * @test
     *
     * @dataProvider statesOfAnOrderThatIsNotCancelled
     */
    public function it_leaves_an_order_alone_that_is_not_cancelled_in_sylius(string $shipmondoState): void
    {
        $order = Orders::create($shipmondoState, 1001);
        $this->commandBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        self::assertSame(Cancellation::NotCancelled, $this->canceller()->cancel($order));

        self::assertSame($shipmondoState, $order->getShipmondoState());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesOfAnOrderThatIsNotCancelled(): iterable
    {
        yield 'pending' => [OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'uploaded' => [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO];
    }

    /**
     * @test
     */
    public function it_leaves_an_order_alone_that_a_guard_a_store_added_holds_back(): void
    {
        $order = self::cancelledOrder(OrderInterface::SHIPMONDO_STATE_PENDING);
        $this->eventDispatcher->addListener('workflow.setono_sylius_shipmondo__order.guard.cancel', static function (GuardEvent $event): void {
            $event->setBlocked(true);
        });

        self::assertSame(Cancellation::NotCancelled, $this->canceller()->cancel($order));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $order->getShipmondoState());
    }

    private function canceller(): OrderCanceller
    {
        return new OrderCanceller($this->commandBus->reveal(), OrderWorkflow::getWorkflow($this->eventDispatcher));
    }

    /**
     * An order with the id 42 that is cancelled in Sylius. An uploaded order is linked to the sales order 1001
     */
    private static function cancelledOrder(string $shipmondoState): Order
    {
        return Orders::create($shipmondoState, 1001, OrderInterface::STATE_CANCELLED, 42);
    }
}
