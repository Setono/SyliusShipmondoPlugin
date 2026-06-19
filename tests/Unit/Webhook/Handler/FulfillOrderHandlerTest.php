<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\FulfillOrderHandler;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Order\StateResolver\StateResolverInterface;
use Sylius\Component\Shipping\ShipmentTransitions;

final class FulfillOrderHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderResolverInterface> */
    private ObjectProphecy $orderResolver;

    /** @var ObjectProphecy<StateMachineInterface> */
    private ObjectProphecy $stateMachine;

    /** @var ObjectProphecy<StateResolverInterface> */
    private ObjectProphecy $orderStateResolver;

    /** @var ObjectProphecy<ObjectManager> */
    private ObjectProphecy $objectManager;

    protected function setUp(): void
    {
        $this->orderResolver = $this->prophesize(OrderResolverInterface::class);
        $this->stateMachine = $this->prophesize(StateMachineInterface::class);
        $this->orderStateResolver = $this->prophesize(StateResolverInterface::class);
        $this->objectManager = $this->prophesize(ObjectManager::class);
    }

    /**
     * @test
     */
    public function it_ships_the_shipments_and_fulfils_the_order_when_fully_shipped(): void
    {
        $shipment = $this->prophesize(ShipmentInterface::class)->reveal();
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipments()->willReturn(new ArrayCollection([$shipment]));

        $payload = ['id' => 1, 'shipped_percent' => 100];
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order->reveal());

        $this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->willReturn(true);
        $this->stateMachine->apply($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->shouldBeCalled();
        $this->orderStateResolver->resolve($order->reveal())->shouldBeCalled();
        $this->objectManager->flush()->shouldBeCalled();

        $this->handle($payload, 'orders', 'status_update');
    }

    /**
     * @test
     */
    public function it_does_nothing_for_another_resource(): void
    {
        $this->expectNoTransition();

        $this->handle(['shipped_percent' => 100], 'shipments', 'status_update');
    }

    /**
     * @test
     */
    public function it_does_nothing_for_another_action(): void
    {
        $this->expectNoTransition();

        $this->handle(['shipped_percent' => 100], 'orders', 'create');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_not_fully_shipped(): void
    {
        // an order + shippable shipment is available, so the only reason not to ship is the percentage
        $this->resolvesToShippableOrder();
        $this->expectNoTransition();

        $this->handle(['id' => 1, 'shipped_percent' => 99], 'orders', 'status_update');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_shipped_percent_is_missing(): void
    {
        $this->resolvesToShippableOrder();
        $this->expectNoTransition();

        $this->handle(['id' => 1], 'orders', 'status_update');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_cannot_be_resolved(): void
    {
        $this->orderResolver->resolveFromPayload(Argument::any())->willReturn(null);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderStateResolver->resolve(Argument::any())->shouldNotBeCalled();
        $this->objectManager->flush()->shouldNotBeCalled();

        $this->handle(['id' => 1, 'shipped_percent' => 100], 'orders', 'status_update');
    }

    /**
     * @test
     */
    public function it_does_not_fulfil_when_the_shipments_are_already_shipped(): void
    {
        $shipment = $this->prophesize(ShipmentInterface::class)->reveal();
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipments()->willReturn(new ArrayCollection([$shipment]));
        $this->orderResolver->resolveFromPayload(Argument::any())->willReturn($order->reveal());

        $this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderStateResolver->resolve(Argument::any())->shouldNotBeCalled();
        $this->objectManager->flush()->shouldNotBeCalled();

        $this->handle(['id' => 1, 'shipped_percent' => 100], 'orders', 'status_update');
    }

    private function resolvesToShippableOrder(): void
    {
        $shipment = $this->prophesize(ShipmentInterface::class)->reveal();
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipments()->willReturn(new ArrayCollection([$shipment]));
        $this->orderResolver->resolveFromPayload(Argument::any())->willReturn($order->reveal());
        $this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->willReturn(true);
    }

    private function expectNoTransition(): void
    {
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderStateResolver->resolve(Argument::any())->shouldNotBeCalled();
        $this->objectManager->flush()->shouldNotBeCalled();
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function handle(array $payload, string $resource, string $action): void
    {
        $handler = new FulfillOrderHandler(
            $this->orderResolver->reveal(),
            $this->stateMachine->reveal(),
            $this->orderStateResolver->reveal(),
            $this->objectManager->reveal(),
        );

        $handler->handle(new RemoteEvent('shipmondo.event', $payload, $resource, $action));
    }
}
