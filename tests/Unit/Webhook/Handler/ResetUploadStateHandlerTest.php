<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\ResetUploadStateHandler;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Event\GuardEvent;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\WebhookPayloadFixtures;

/**
 * Exercises the handler with the real order state machine (including its guard) and real orders
 */
final class ResetUploadStateHandlerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * The id of the sales order in the captured orders_delete payload
     */
    private const SALES_ORDER_ID = 37707018;

    /** @var ObjectProphecy<OrderResolverInterface> */
    private ObjectProphecy $orderResolver;

    /** @var ObjectProphecy<ManagerRegistry> */
    private ObjectProphecy $managerRegistry;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    /**
     * The order state machine's event dispatcher, for guards a store adds
     */
    private EventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->orderResolver = $this->prophesize(OrderResolverInterface::class);
        $this->managerRegistry = $this->prophesize(ManagerRegistry::class);
        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
        $this->eventDispatcher = new EventDispatcher();
    }

    /**
     * @test
     */
    public function it_sends_the_order_back_to_pending_to_be_uploaded_again_when_its_sales_order_is_deleted(): void
    {
        // real captured payload of a deleted/archived Shipmondo sales order
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = $this->resolve($payload, self::uploadedOrder(self::SALES_ORDER_ID));
        $this->expectFlush();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_PENDING, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * Shipmondo couldn't delete the sales order when the order was cancelled, so an admin deleted it in Shipmondo
     *
     * @test
     */
    public function it_cancels_a_cancelled_order_when_its_sales_order_is_deleted(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = self::uploadedOrder(self::SALES_ORDER_ID);
        $order->setState(OrderInterface::STATE_CANCELLED);
        $this->resolve($payload, $order);
        $this->expectFlush();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_CANCELLED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @test
     */
    public function it_does_nothing_for_a_non_delete_action(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $this->expectNoInteraction();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::StatusUpdate);
    }

    /**
     * @test
     */
    public function it_does_nothing_for_another_resource(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $this->expectNoInteraction();

        $this->handle($payload, WebhookResourceName::Shipments, WebhookAction::Cancel);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_cannot_be_resolved(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $this->orderResolver->resolveFromPayload($payload)->willReturn(null);
        $this->expectNoFlush();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_payload_has_no_sales_order_id(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        unset($payload['id']);
        $this->expectNoInteraction();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_is_linked_to_another_sales_order(): void
    {
        // e.g. a deleted duplicate of the order (same order_id, another sales order id)
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = $this->resolve($payload, self::uploadedOrder(11111111));
        $this->expectNoFlush();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 11111111], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_is_no_longer_linked_to_a_sales_order(): void
    {
        // e.g. the delete echoed back after our own DeleteOrder, which already cancelled the order
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = new Order();
        $order->setState(OrderInterface::STATE_CANCELLED);
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_CANCELLED);
        $this->resolve($payload, $order);
        $this->expectNoFlush();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);

        self::assertSame(OrderInterface::SHIPMONDO_STATE_CANCELLED, $order->getShipmondoState());
    }

    /**
     * @test
     */
    public function it_does_nothing_when_a_guard_a_store_added_holds_the_order_back(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = $this->resolve($payload, self::uploadedOrder(self::SALES_ORDER_ID));
        $this->eventDispatcher->addListener('workflow.setono_sylius_shipmondo__order.guard.reset', static function (GuardEvent $event): void {
            $event->setBlocked(true);
        });
        $this->expectNoFlush();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $order->getShipmondoState());
    }

    private static function uploadedOrder(int $salesOrderId): Order
    {
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => $salesOrderId]);

        return $order;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolve(array $payload, Order $order): Order
    {
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order);

        return $order;
    }

    private function expectFlush(): void
    {
        $this->managerRegistry->getManagerForClass(Order::class)->willReturn($this->entityManager->reveal());
        $this->entityManager->flush()->shouldBeCalledOnce();
    }

    private function expectNoFlush(): void
    {
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();
    }

    private function expectNoInteraction(): void
    {
        $this->orderResolver->resolveFromPayload(Argument::any())->shouldNotBeCalled();
        $this->expectNoFlush();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handle(array $payload, WebhookResourceName $resource, WebhookAction $action): void
    {
        $handler = new ResetUploadStateHandler(
            $this->orderResolver->reveal(),
            OrderWorkflow::getWorkflow($this->eventDispatcher),
            $this->managerRegistry->reveal(),
        );

        $handler->handle(new RemoteEvent('shipmondo.event', $payload, $resource, $action));
    }
}
