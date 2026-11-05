<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCanceller;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\SalesOrderDeletedHandler;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Orders;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\WebhookPayloadFixtures;

/**
 * Exercises the handler with the real order state machine (including its guard), the real canceller and real orders
 */
final class SalesOrderDeletedHandlerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * The id of the sales order in the captured orders_delete payload
     */
    private const SALES_ORDER_ID = 37707018;

    /**
     * The order state machine's event dispatcher, for guards a store adds
     */
    private EventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->eventDispatcher = new EventDispatcher();
    }

    /**
     * @test
     */
    public function it_handles_the_webhook_of_a_deleted_sales_order(): void
    {
        self::assertSame([WebhookAction::Delete], SalesOrderDeletedHandler::getSubscribedActions());
    }

    /**
     * @test
     */
    public function it_sends_the_order_back_to_pending_to_be_uploaded_again_when_its_sales_order_is_deleted(): void
    {
        $order = self::uploadedOrder(self::SALES_ORDER_ID);

        // real captured payload of a deleted/archived Shipmondo sales order
        $this->handle($order, WebhookPayloadFixtures::load('orders_delete'));

        self::assertSame([OrderInterface::SHIPMONDO_STATE_PENDING, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * Shipmondo couldn't delete the sales order when the order was cancelled, so an admin deleted it in Shipmondo
     *
     * @test
     */
    public function it_cancels_a_cancelled_order_when_its_sales_order_is_deleted(): void
    {
        $order = Orders::create(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, self::SALES_ORDER_ID, OrderInterface::STATE_CANCELLED);

        $this->handle($order, WebhookPayloadFixtures::load('orders_delete'));

        self::assertSame([OrderInterface::SHIPMONDO_STATE_CANCELLED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_payload_has_no_sales_order_id(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        unset($payload['id']);
        $order = self::uploadedOrder(self::SALES_ORDER_ID);

        $this->handle($order, $payload);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, self::SALES_ORDER_ID], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * An order the plugin didn't move through its Shipmondo state, such as one 1.x left uploaded without a link
     *
     * @test
     */
    public function it_does_nothing_when_the_payload_has_no_sales_order_id_and_the_order_has_no_link_either(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        unset($payload['id']);
        $order = self::uploadedOrder(self::SALES_ORDER_ID);
        (new \ReflectionProperty($order, 'shipmondoId'))->setValue($order, null);

        $this->handle($order, $payload);

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $order->getShipmondoState());
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_is_linked_to_another_sales_order(): void
    {
        // e.g. a deleted duplicate of the order (same order_id, another sales order id), found by its order number
        $order = self::uploadedOrder(11111111);

        $this->handle($order, WebhookPayloadFixtures::load('orders_delete'));

        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 11111111], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_is_no_longer_linked_to_a_sales_order(): void
    {
        // e.g. the delete echoed back after our own DeleteOrder, which already cancelled the order
        $order = Orders::create(OrderInterface::SHIPMONDO_STATE_CANCELLED, state: OrderInterface::STATE_CANCELLED);

        $this->handle($order, WebhookPayloadFixtures::load('orders_delete'));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_CANCELLED, $order->getShipmondoState());
    }

    /**
     * @test
     */
    public function it_does_nothing_when_a_guard_a_store_added_holds_the_order_back(): void
    {
        $order = self::uploadedOrder(self::SALES_ORDER_ID);
        $this->eventDispatcher->addListener('workflow.setono_sylius_shipmondo__order.guard.reset', static function (GuardEvent $event): void {
            $event->setBlocked(true);
        });

        $this->handle($order, WebhookPayloadFixtures::load('orders_delete'));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $order->getShipmondoState());
    }

    private static function uploadedOrder(int $salesOrderId): Order
    {
        return Orders::create(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $salesOrderId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handle(Order $order, array $payload): void
    {
        $orderWorkflow = OrderWorkflow::getWorkflow($this->eventDispatcher);

        // the order is pending by the time the canceller gets it, so it has no sales order to delete
        $commandBus = $this->prophesize(MessageBusInterface::class);
        $commandBus->dispatch(Argument::cetera())->shouldNotBeCalled();

        $handler = new SalesOrderDeletedHandler($orderWorkflow, new OrderCanceller($commandBus->reveal(), $orderWorkflow));

        $handler->handle($order, new RemoteEvent('shipmondo.event', $payload, WebhookResourceName::Orders, WebhookAction::Delete));
    }
}
