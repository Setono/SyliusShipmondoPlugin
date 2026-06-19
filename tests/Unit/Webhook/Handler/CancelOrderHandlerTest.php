<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\CancelOrderHandler;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\OrderTransitions;

final class CancelOrderHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderResolverInterface> */
    private ObjectProphecy $orderResolver;

    /** @var ObjectProphecy<StateMachineInterface> */
    private ObjectProphecy $stateMachine;

    /** @var ObjectProphecy<ManagerRegistry> */
    private ObjectProphecy $managerRegistry;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    protected function setUp(): void
    {
        $this->orderResolver = $this->prophesize(OrderResolverInterface::class);
        $this->stateMachine = $this->prophesize(StateMachineInterface::class);
        $this->managerRegistry = $this->prophesize(ManagerRegistry::class);
        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
    }

    /**
     * @test
     */
    public function it_cancels_the_order_on_a_cancelled_status_update(): void
    {
        $order = $this->resolvesToCancellableOrder();
        $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->shouldBeCalled();
        $this->expectFlush();

        $this->handle(['id' => 1, 'order_status' => 'cancelled'], 'orders', 'status_update');
    }

    /**
     * @test
     */
    public function it_cancels_the_order_on_a_delete_action(): void
    {
        $order = $this->resolvesToCancellableOrder();
        $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->shouldBeCalled();
        $this->expectFlush();

        $this->handle(['id' => 1], 'orders', 'delete');
    }

    /**
     * @test
     */
    public function it_does_nothing_for_another_resource(): void
    {
        $this->expectNoCancellation();

        $this->handle(['id' => 1], 'shipments', 'cancel');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_status_is_not_cancelled(): void
    {
        $this->expectNoCancellation();

        $this->handle(['id' => 1, 'order_status' => 'received'], 'orders', 'status_update');
    }

    /**
     * @test
     */
    public function it_does_nothing_for_a_non_cancellation_action(): void
    {
        $this->expectNoCancellation();

        $this->handle(['id' => 1], 'orders', 'create');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_cannot_be_resolved(): void
    {
        $this->orderResolver->resolveFromPayload(Argument::any())->willReturn(null);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle(['id' => 1], 'orders', 'delete');
    }

    /**
     * @test
     */
    public function it_does_not_cancel_when_the_transition_is_not_allowed(): void
    {
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderResolver->resolveFromPayload(Argument::any())->willReturn($order);
        $this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle(['id' => 1], 'orders', 'delete');
    }

    private function resolvesToCancellableOrder(): OrderInterface
    {
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderResolver->resolveFromPayload(Argument::any())->willReturn($order);
        $this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->willReturn(true);

        return $order;
    }

    private function expectFlush(): void
    {
        $this->managerRegistry->getManagerForClass(Argument::any())->willReturn($this->entityManager->reveal());
        $this->entityManager->flush()->shouldBeCalled();
    }

    private function expectNoCancellation(): void
    {
        $this->orderResolver->resolveFromPayload(Argument::any())->shouldNotBeCalled();
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function handle(array $payload, string $resource, string $action): void
    {
        $handler = new CancelOrderHandler(
            $this->orderResolver->reveal(),
            $this->stateMachine->reveal(),
            $this->managerRegistry->reveal(),
        );

        $handler->handle(new RemoteEvent('shipmondo.event', $payload, $resource, $action));
    }
}
