<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\EventListener\OrderCancellationListener;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
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
     */
    public function it_cancels_the_order_in_shipmondo_and_tells_the_user_its_sales_order_was_deleted(): void
    {
        $order = self::uploadedOrder();
        // deleting the sales order cancels the order, which unlinks it
        $this->orderCanceller->cancel($order)->will(static function () use ($order): bool {
            $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_CANCELLED);

            return true;
        })->shouldBeCalledOnce();

        $this->listener($this->requestStackWithSession())($order);

        self::assertSame(['setono_sylius_shipmondo.order_deleted_in_shipmondo'], $this->session->getFlashBag()->peek('info'));
        self::assertSame([], $this->session->getFlashBag()->peek('error'));
    }

    /**
     * @test
     */
    public function it_tells_the_user_when_the_sales_order_could_not_be_deleted(): void
    {
        $order = self::uploadedOrder();
        $this->orderCanceller->cancel($order)->willReturn(false);

        $this->listener($this->requestStackWithSession())($order);

        self::assertSame(['setono_sylius_shipmondo.order_not_deleted_in_shipmondo'], $this->session->getFlashBag()->peek('error'));
        self::assertSame([], $this->session->getFlashBag()->peek('info'));
    }

    /**
     * @test
     */
    public function it_tells_the_user_the_sales_order_will_be_deleted_when_that_happens_asynchronously(): void
    {
        $order = self::uploadedOrder();
        $this->orderCanceller->cancel($order)->willReturn(null);

        $this->listener($this->requestStackWithSession())($order);

        self::assertSame(['setono_sylius_shipmondo.order_will_be_deleted_in_shipmondo'], $this->session->getFlashBag()->peek('info'));
        self::assertSame([], $this->session->getFlashBag()->peek('error'));
    }

    /**
     * @test
     */
    public function it_cancels_an_order_that_was_not_uploaded_without_telling_the_user(): void
    {
        $order = new Order();
        $this->orderCanceller->cancel($order)->willReturn(true)->shouldBeCalledOnce();

        $this->listener($this->requestStackWithSession())($order);

        self::assertSame([], $this->session->getFlashBag()->all());
    }

    /**
     * @test
     */
    public function it_cancels_the_order_without_telling_anyone_when_there_is_no_request(): void
    {
        $order = self::uploadedOrder();
        $this->orderCanceller->cancel($order)->willReturn(true)->shouldBeCalledOnce();

        // an empty request stack has no session, so no flash is added and no error is thrown
        $this->listener(new RequestStack())($order);
    }

    /**
     * @test
     */
    public function it_does_not_flash_when_the_session_has_no_flash_bag(): void
    {
        $order = self::uploadedOrder();
        $this->orderCanceller->cancel($order)->willReturn(true)->shouldBeCalledOnce();

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
        $order = self::uploadedOrder();
        $this->orderCanceller->cancel($order)->willReturn(null)->shouldBeCalledOnce();

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

    private static function uploadedOrder(): Order
    {
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 12345]);

        return $order;
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
