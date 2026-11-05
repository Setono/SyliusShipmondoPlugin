<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\SalesOrderShippedHandler;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Order\StateResolver\StateResolverInterface;
use Sylius\Component\Shipping\ShipmentTransitions;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\WebhookPayloadFixtures;

final class SalesOrderShippedHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<StateMachineInterface> */
    private ObjectProphecy $stateMachine;

    /** @var ObjectProphecy<StateResolverInterface> */
    private ObjectProphecy $orderStateResolver;

    private ShipmentInterface $shipment;

    private OrderInterface $order;

    protected function setUp(): void
    {
        $this->stateMachine = $this->prophesize(StateMachineInterface::class);
        $this->orderStateResolver = $this->prophesize(StateResolverInterface::class);

        $this->shipment = $this->prophesize(ShipmentInterface::class)->reveal();
        $order = $this->prophesize(OrderInterface::class);
        $order->getShipments()->willReturn(new ArrayCollection([$this->shipment]));
        $this->order = $order->reveal();
    }

    /**
     * `status_update` too, so a later status change reporting full shipment is reacted to
     *
     * @test
     */
    public function it_handles_the_webhooks_that_can_report_a_sales_order_fully_shipped(): void
    {
        self::assertSame([WebhookAction::CreateShipment, WebhookAction::StatusUpdate], SalesOrderShippedHandler::getSubscribedActions());
    }

    /**
     * @test
     */
    public function it_ships_and_fulfils_the_order_when_shipmondo_reports_it_fully_shipped(): void
    {
        // real captured payload: a fully shipped order (order_status "sent", shipped_percent 100)
        $this->stateMachine->can($this->shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->willReturn(true);
        $this->stateMachine->apply($this->shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->shouldBeCalled();
        $this->orderStateResolver->resolve($this->order)->shouldBeCalled();

        $this->handle(WebhookPayloadFixtures::load('orders_create_shipment'));
    }

    /**
     * @test
     */
    public function it_does_nothing_on_a_status_update_that_is_only_fulfilled_not_shipped(): void
    {
        // real captured payload: order is fulfilled/packed (fulfilled_percent 100) but shipped_percent 0
        $this->expectNoTransition();

        $this->handle(WebhookPayloadFixtures::load('orders_status_update'));
    }

    /**
     * @test
     */
    public function it_does_nothing_when_not_fully_shipped(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_create_shipment');
        $payload['shipped_percent'] = 99;
        $this->expectNoTransition();

        $this->handle($payload);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_payload_has_no_shipped_percent(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_create_shipment');
        unset($payload['shipped_percent']);
        $this->expectNoTransition();

        $this->handle($payload);
    }

    /**
     * @test
     */
    public function it_does_not_fulfil_when_the_shipments_are_already_shipped(): void
    {
        $this->stateMachine->can($this->shipment, ShipmentTransitions::GRAPH, ShipmentTransitions::TRANSITION_SHIP)->willReturn(false);
        $this->expectNoTransition();

        $this->handle(WebhookPayloadFixtures::load('orders_create_shipment'));
    }

    private function expectNoTransition(): void
    {
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderStateResolver->resolve(Argument::any())->shouldNotBeCalled();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handle(array $payload): void
    {
        $handler = new SalesOrderShippedHandler($this->stateMachine->reveal(), $this->orderStateResolver->reveal());

        $handler->handle($this->order, new RemoteEvent('shipmondo.event', $payload, WebhookResourceName::Orders, WebhookAction::CreateShipment));
    }
}
