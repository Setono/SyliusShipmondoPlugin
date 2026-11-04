<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Canceller\Cancellation;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\EventListener\OrderCancellationListener;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Orders;

final class OrderCancellationListenerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderCancellerInterface> */
    private ObjectProphecy $orderCanceller;

    private Session $session;

    protected function setUp(): void
    {
        $this->orderCanceller = $this->prophesize(OrderCancellerInterface::class);
        $this->session = new Session(new MockArraySessionStorage());
    }

    /**
     * @test
     *
     * @dataProvider deletions
     *
     * @param array<string, list<string>> $flashes
     */
    public function it_tells_the_user_whether_the_sales_order_was_deleted(Cancellation $cancellation, array $flashes): void
    {
        $order = self::cancelledOrder();
        $this->orderCanceller->cancel($order)->willReturn($cancellation)->shouldBeCalledOnce();

        $this->listener($this->requestStackWithSession())($order);

        self::assertSame($flashes, $this->session->getFlashBag()->all());
    }

    /**
     * @return iterable<string, array{Cancellation, array<string, list<string>>}>
     */
    public static function deletions(): iterable
    {
        yield 'deleted' => [Cancellation::SalesOrderDeleted, ['info' => ['setono_sylius_shipmondo.order_deleted_in_shipmondo']]];
        yield 'deleted later' => [Cancellation::SalesOrderDeletedLater, ['info' => ['setono_sylius_shipmondo.order_will_be_deleted_in_shipmondo']]];
        yield 'not deleted' => [Cancellation::SalesOrderNotDeleted, ['error' => ['setono_sylius_shipmondo.order_not_deleted_in_shipmondo']]];
    }

    /**
     * @test
     *
     * @dataProvider cancellationsWithoutASalesOrderToDelete
     */
    public function it_does_not_tell_the_user_anything_when_there_was_no_sales_order_to_delete(Cancellation $cancellation): void
    {
        $order = self::cancelledOrder();
        $this->orderCanceller->cancel($order)->willReturn($cancellation)->shouldBeCalledOnce();

        $this->listener($this->requestStackWithSession())($order);

        self::assertSame([], $this->session->getFlashBag()->all());
    }

    /**
     * @return iterable<string, array{Cancellation}>
     */
    public static function cancellationsWithoutASalesOrderToDelete(): iterable
    {
        yield 'cancelled right away' => [Cancellation::Cancelled];
        yield 'left to its upload' => [Cancellation::LeftToUpload];
        yield 'not cancelled' => [Cancellation::NotCancelled];
    }

    /**
     * @test
     */
    public function it_cancels_the_order_without_telling_anyone_when_there_is_no_request(): void
    {
        $order = self::cancelledOrder();
        $this->orderCanceller->cancel($order)->willReturn(Cancellation::SalesOrderDeleted)->shouldBeCalledOnce();

        // an empty request stack has no session, so no flash is added and no error is thrown
        $this->listener(new RequestStack())($order);
    }

    /**
     * @test
     */
    public function it_does_not_flash_when_the_session_has_no_flash_bag(): void
    {
        $order = self::cancelledOrder();
        $this->orderCanceller->cancel($order)->willReturn(Cancellation::SalesOrderDeleted)->shouldBeCalledOnce();

        $request = new Request();
        $request->setSession($this->prophesize(SessionInterface::class)->reveal());
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $this->listener($requestStack)($order);
    }

    /**
     * @test
     */
    public function it_reacts_to_the_symfony_workflow_completed_event(): void
    {
        $order = self::cancelledOrder();
        $this->orderCanceller->cancel($order)->willReturn(Cancellation::SalesOrderDeletedLater)->shouldBeCalledOnce();

        $this->listener(new RequestStack())->onOrderCancelled($this->cancelEvent($order));
    }

    /**
     * @test
     */
    public function it_ignores_a_workflow_event_whose_subject_is_not_an_order(): void
    {
        $this->orderCanceller->cancel(Argument::any())->shouldNotBeCalled();

        $this->listener(new RequestStack())->onOrderCancelled($this->cancelEvent(new \stdClass()));
    }

    private function listener(RequestStack $requestStack): OrderCancellationListener
    {
        return new OrderCancellationListener($this->orderCanceller->reveal(), $requestStack);
    }

    private static function cancelledOrder(): Order
    {
        return Orders::create(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 12345, OrderInterface::STATE_CANCELLED);
    }

    private function requestStackWithSession(): RequestStack
    {
        $request = new Request();
        $request->setSession($this->session);

        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    private function cancelEvent(object $subject): CompletedEvent
    {
        return new CompletedEvent(
            $subject,
            new Marking(),
            new Transition('cancel', 'new', 'cancelled'),
            $this->prophesize(WorkflowInterface::class)->reveal(),
        );
    }
}
