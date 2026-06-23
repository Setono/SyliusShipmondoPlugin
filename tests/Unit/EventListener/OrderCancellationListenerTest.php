<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\EventListener;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\EventListener\OrderCancellationListener;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderCancellationListenerTest extends TestCase
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
     */
    public function it_dispatches_a_delete_command_and_flashes_when_a_cancelled_order_was_uploaded(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipmondoId()->willReturn(12345);
        $order->getId()->willReturn(42);

        $this->commandBus->dispatch(Argument::that(
            static fn (object $message): bool => $message instanceof DeleteOrder && 42 === $message->order,
        ))->willReturn(new Envelope(new \stdClass()))->shouldBeCalled();

        $session = new Session(new MockArraySessionStorage());

        $listener = $this->listener($this->requestStackWith($session));
        $listener($order->reveal());

        self::assertContains('setono_sylius_shipmondo.order_deleted_in_shipmondo', $session->getFlashBag()->peek('info'));
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_cancelled_order_was_not_uploaded(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipmondoId()->willReturn(null);

        $this->commandBus->dispatch(Argument::any())->shouldNotBeCalled();

        $listener = $this->listener(new RequestStack());
        $listener($order->reveal());
    }

    /**
     * @test
     */
    public function it_dispatches_without_flashing_when_there_is_no_request(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipmondoId()->willReturn(12345);
        $order->getId()->willReturn(42);

        $this->commandBus->dispatch(Argument::any())->willReturn(new Envelope(new \stdClass()))->shouldBeCalled();

        // an empty request stack has no session, so no flash is added and no error is thrown
        $listener = $this->listener(new RequestStack());
        $listener($order->reveal());
    }

    /**
     * @test
     */
    public function it_reacts_to_the_symfony_workflow_completed_event(): void
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipmondoId()->willReturn(12345);
        $order->getId()->willReturn(42);

        $this->commandBus->dispatch(Argument::type(DeleteOrder::class))
            ->willReturn(new Envelope(new \stdClass()))
            ->shouldBeCalled()
        ;

        $this->listener(new RequestStack())->onOrderCancelled($this->cancelEvent($order->reveal()));
    }

    /**
     * @test
     */
    public function it_ignores_a_workflow_event_whose_subject_is_not_an_order(): void
    {
        $this->commandBus->dispatch(Argument::any())->shouldNotBeCalled();

        $this->listener(new RequestStack())->onOrderCancelled($this->cancelEvent(new \stdClass()));
    }

    private function listener(RequestStack $requestStack): OrderCancellationListener
    {
        return new OrderCancellationListener($this->commandBus->reveal(), $requestStack);
    }

    private function requestStackWith(Session $session): RequestStack
    {
        $request = new Request();
        $request->setSession($session);

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
