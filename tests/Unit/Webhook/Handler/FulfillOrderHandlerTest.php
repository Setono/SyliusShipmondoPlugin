<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\WebhookPayloadFixtures;

final class FulfillOrderHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderResolverInterface> */
    private ObjectProphecy $orderResolver;

    /** @var ObjectProphecy<StateMachineInterface> */
    private ObjectProphecy $stateMachine;

    /** @var ObjectProphecy<StateResolverInterface> */
    private ObjectProphecy $orderStateResolver;

    /** @var ObjectProphecy<ManagerRegistry> */
    private ObjectProphecy $managerRegistry;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    protected function setUp(): void
    {
        $this->orderResolver = $this->prophesize(OrderResolverInterface::class);
        $this->stateMachine = $this->prophesize(StateMachineInterface::class);
        $this->orderStateResolver = $this->prophesize(StateResolverInterface::class);
        $this->managerRegistry = $this->prophesize(ManagerRegistry::class);
        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
    }

    /**
     * @test
     */
    public function it_ships_and_fulfils_the_order_on_create_shipment(): void
    {
        // real captured payload: a fully shipped order (order_status "sent", shipped_percent 100)
        $payload = WebhookPayloadFixtures::load('orders_create_shipment');
        $this->expectShipAndFulfil($payload);

        $this->handle($payload, 'orders', 'create_shipment');
    }

    /**
     * @test
     */
    public function it_also_fulfils_when_a_status_update_reports_full_shipment(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_create_shipment');
        $this->expectShipAndFulfil($payload);

        $this->handle($payload, 'orders', 'status_update');
    }

    /**
     * @test
     */
    public function it_does_nothing_on_a_status_update_that_is_only_fulfilled_not_shipped(): void
    {
        // real captured payload: order is fulfilled/packed (fulfilled_percent 100) but shipped_percent 0
        $payload = WebhookPayloadFixtures::load('orders_status_update');
        $this->expectNoResolutionOrTransition();

        $this->handle($payload, 'orders', 'status_update');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_not_fully_shipped(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_create_shipment');
        $payload['shipped_percent'] = 99;
        $this->expectNoResolutionOrTransition();

        $this->handle($payload, 'orders', 'create_shipment');
    }

    /**
     * @test
     */
    public function it_does_nothing_on_create_fulfillment(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_create_fulfillment');
        $this->expectNoResolutionOrTransition();

        $this->handle($payload, 'orders', 'create_fulfillment');
    }

    /**
     * @test
     */
    public function it_does_nothing_for_another_resource(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_create_shipment');
        $this->expectNoResolutionOrTransition();

        $this->handle($payload, 'shipments', 'create');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_cannot_be_resolved(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_create_shipment');
        $this->orderResolver->resolveFromPayload($payload)->willReturn(null);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderStateResolver->resolve(Argument::any())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle($payload, 'orders', 'create_shipment');
    }

    /**
     * @test
     */
    public function it_does_not_fulfil_when_the_shipments_are_already_shipped(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_create_shipment');

        $shipment = $this->prophesize(ShipmentInterface::class)->reveal();
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipments()->willReturn(new ArrayCollection([$shipment]));
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order->reveal());

        $this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderStateResolver->resolve(Argument::any())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle($payload, 'orders', 'create_shipment');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function expectShipAndFulfil(array $payload): void
    {
        $shipment = $this->prophesize(ShipmentInterface::class)->reveal();
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipments()->willReturn(new ArrayCollection([$shipment]));
        $orderRevealed = $order->reveal();

        $this->orderResolver->resolveFromPayload($payload)->willReturn($orderRevealed);
        $this->stateMachine->can($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->willReturn(true);
        $this->stateMachine->apply($shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->shouldBeCalled();
        $this->orderStateResolver->resolve($orderRevealed)->shouldBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->willReturn($this->entityManager->reveal());
        $this->entityManager->flush()->shouldBeCalled();
    }

    private function expectNoResolutionOrTransition(): void
    {
        $this->orderResolver->resolveFromPayload(Argument::any())->shouldNotBeCalled();
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderStateResolver->resolve(Argument::any())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handle(array $payload, string $resource, string $action): void
    {
        $handler = new FulfillOrderHandler(
            $this->orderResolver->reveal(),
            $this->stateMachine->reveal(),
            $this->orderStateResolver->reveal(),
            $this->managerRegistry->reveal(),
        );

        $handler->handle(new RemoteEvent('shipmondo.event', $payload, $resource, $action));
    }
}
