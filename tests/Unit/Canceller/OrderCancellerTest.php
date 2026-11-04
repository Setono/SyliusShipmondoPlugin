<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Canceller;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCanceller;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

/**
 * Exercises the canceller with the real order state machine (including its guard) and real orders
 */
final class OrderCancellerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $commandBus;

    protected function setUp(): void
    {
        $this->commandBus = $this->prophesize(MessageBusInterface::class);
    }

    /**
     * @test
     *
     * @dataProvider statesWithoutASalesOrder
     */
    public function it_cancels_an_order_that_shipmondo_has_no_sales_order_for_right_away(string $state): void
    {
        $order = self::cancelledOrder($state);
        $this->commandBus->dispatch(Argument::any())->shouldNotBeCalled();

        self::assertTrue($this->canceller()->cancel($order));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_CANCELLED, $order->getShipmondoState());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesWithoutASalesOrder(): iterable
    {
        yield 'pending' => [OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'failed' => [OrderInterface::SHIPMONDO_STATE_FAILED];
    }

    /**
     * @test
     *
     * @dataProvider deletions
     */
    public function it_deletes_the_sales_order_of_an_uploaded_order_which_cancels_the_order(?HandledStamp $handled, ?bool $deleted): void
    {
        $order = self::cancelledOrder(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO);
        $this->commandBus->dispatch(Argument::that(
            static fn (object $message): bool => $message instanceof DeleteOrder && 42 === $message->order,
        ))->willReturn(new Envelope(new \stdClass(), null === $handled ? [] : [$handled]))->shouldBeCalledOnce();

        self::assertSame($deleted, $this->canceller()->cancel($order));

        // DeleteOrder cancels the order once the sales order is gone
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @return iterable<string, array{HandledStamp|null, bool|null}>
     */
    public static function deletions(): iterable
    {
        yield 'deleted' => [new HandledStamp(true, 'DeleteOrderHandler::__invoke'), true];
        yield 'could not be deleted' => [new HandledStamp(false, 'DeleteOrderHandler::__invoke'), false];
        yield 'deleted later, by an asynchronous transport' => [null, null];
    }

    /**
     * @test
     */
    public function it_leaves_an_order_in_uploading_to_its_upload(): void
    {
        $order = self::cancelledOrder(OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO);
        $this->commandBus->dispatch(Argument::any())->shouldNotBeCalled();

        self::assertNull($this->canceller()->cancel($order));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO, $order->getShipmondoState());
    }

    /**
     * @test
     */
    public function it_leaves_an_order_alone_that_is_not_cancelled_in_sylius(): void
    {
        $order = new Order();

        self::assertNull($this->canceller()->cancel($order));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $order->getShipmondoState());
    }

    private function canceller(): OrderCanceller
    {
        return new OrderCanceller($this->commandBus->reveal(), OrderWorkflow::getWorkflow(new EventDispatcher()));
    }

    /**
     * An order with the id 42 that is cancelled in Sylius, in the given Shipmondo state. An uploaded order is linked to
     * the sales order 1001
     */
    private static function cancelledOrder(string $shipmondoState): Order
    {
        $order = new Order();
        (new \ReflectionProperty($order, 'id'))->setValue($order, 42);
        $order->setState(OrderInterface::STATE_CANCELLED);
        $order->setShipmondoState($shipmondoState, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 1001]);

        return $order;
    }
}
