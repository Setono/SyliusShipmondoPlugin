<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlers;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\WebhookConsumer;
use Symfony\Component\RemoteEvent\RemoteEvent as BaseRemoteEvent;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Orders;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;

final class WebhookConsumerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderResolverInterface> */
    private ObjectProphecy $orderResolver;

    /** @var ObjectProphecy<ManagerRegistry> */
    private ObjectProphecy $managerRegistry;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    private RecordingLogger $logger;

    /**
     * What the handlers were handed, in the order they ran
     *
     * @var \ArrayObject<int, array{string, OrderInterface, RemoteEvent}>
     */
    private \ArrayObject $handled;

    protected function setUp(): void
    {
        $this->orderResolver = $this->prophesize(OrderResolverInterface::class);
        $this->managerRegistry = $this->prophesize(ManagerRegistry::class);
        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
        $this->logger = new RecordingLogger();
        $this->handled = new \ArrayObject();
    }

    /**
     * @test
     */
    public function it_hands_the_order_to_each_handler_subscribed_to_the_action_in_turn_and_flushes_once(): void
    {
        $event = self::event(WebhookAction::StatusUpdate);
        $order = Orders::create(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 37707019);
        $this->orderResolver->resolveFromPayload($event->getPayload())->willReturn($order)->shouldBeCalledOnce();
        $this->managerRegistry->getManagerForClass(Order::class)->willReturn($this->entityManager->reveal());
        $this->entityManager->flush()->shouldBeCalledOnce();

        $this->consume($event, [
            'status_update' => [$this->spy('first'), $this->spy('second')],
            'delete' => [$this->spy('not subscribed')],
        ]);

        self::assertSame([['first', $order, $event], ['second', $order, $event]], $this->handled->getArrayCopy());
        self::assertSame([], $this->logger->records);
    }

    /**
     * Shipmondo still sends the webhooks it was told to send before the last webhook registration
     *
     * @test
     */
    public function it_ignores_an_action_no_handler_subscribes_to(): void
    {
        $this->expectNothingResolvedOrFlushed();

        $this->consume(self::event(WebhookAction::CreateFulfillment), ['create_shipment' => [$this->spy('shipped')], 'delete' => [$this->spy('deleted')]]);

        self::assertSame([], $this->handled->getArrayCopy());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_ignores_a_webhook_about_another_resource_even_when_a_handler_handles_its_action(): void
    {
        $this->expectNothingResolvedOrFlushed();

        $this->consume(self::event(WebhookAction::CreateShipment, WebhookResourceName::Shipments), ['create_shipment' => [$this->spy('shipped')]]);

        self::assertSame([], $this->handled->getArrayCopy());
    }

    /**
     * e.g. a sales order of another shop on the same Shipmondo account
     *
     * @test
     */
    public function it_logs_a_webhook_whose_order_it_cannot_find(): void
    {
        $event = self::event(WebhookAction::CreateShipment);
        $this->orderResolver->resolveFromPayload($event->getPayload())->willReturn(null);
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->consume($event, ['create_shipment' => [$this->spy('shipped')]]);

        self::assertSame([], $this->handled->getArrayCopy());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'No order is linked to the sales order {salesOrder} (order number {orderNumber}) of the Shipmondo webhook orders/{action}, so the webhook is ignored',
            'context' => ['salesOrder' => 37707019, 'orderNumber' => '000000011', 'action' => 'create_shipment'],
        ]], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_rejects_a_remote_event_that_is_not_a_shipmondo_event(): void
    {
        $this->expectNothingResolvedOrFlushed();

        $this->expectException(\InvalidArgumentException::class);

        $this->consume(new BaseRemoteEvent('name', 'id', []), ['create_shipment' => [$this->spy('shipped')]]);
    }

    private function expectNothingResolvedOrFlushed(): void
    {
        $this->orderResolver->resolveFromPayload(Argument::any())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();
    }

    private function spy(string $name): OrderWebhookHandlerSpy
    {
        return new OrderWebhookHandlerSpy($name, $this->handled);
    }

    private static function event(WebhookAction $action, WebhookResourceName $resource = WebhookResourceName::Orders): RemoteEvent
    {
        return new RemoteEvent('shipmondo.event', WebhookPayloadFixtures::load('orders_create_shipment'), $resource, $action);
    }

    /**
     * @param array<string, list<OrderWebhookHandlerSpy>> $handlers the handlers of each action, by the action's value
     */
    private function consume(BaseRemoteEvent $event, array $handlers): void
    {
        $consumer = new WebhookConsumer($this->orderResolver->reveal(), new OrderWebhookHandlers($handlers), $this->logger, $this->managerRegistry->reveal());
        $consumer->consume($event);
    }
}
