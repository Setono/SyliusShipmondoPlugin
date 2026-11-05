<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use PHPUnit\Framework\TestCase;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlers;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\OrderWebhookHandlerSpy;

final class OrderWebhookHandlersTest extends TestCase
{
    /**
     * @test
     */
    public function it_returns_the_handlers_of_an_action_in_the_order_they_run_in_as_a_list(): void
    {
        $log = new \ArrayObject();
        $first = new OrderWebhookHandlerSpy('first', $log);
        $second = new OrderWebhookHandlerSpy('second', $log);

        // the container hands over a lazy iterator for each action
        $handlers = new OrderWebhookHandlers([
            'status_update' => new \ArrayIterator([$first, $second]),
            'delete' => [new OrderWebhookHandlerSpy('deleted', $log)],
        ]);

        self::assertSame([$first, $second], $handlers->forAction(WebhookAction::StatusUpdate));
        self::assertSame([], $handlers->forAction(WebhookAction::CreateFulfillment));
    }

    /**
     * @test
     */
    public function it_returns_the_actions_a_handler_is_subscribed_to_in_the_order_of_the_sdks_actions(): void
    {
        $log = new \ArrayObject();

        $handlers = new OrderWebhookHandlers([
            'delete' => [new OrderWebhookHandlerSpy('deleted', $log)],
            'create_shipment' => [new OrderWebhookHandlerSpy('shipped', $log)],
            'status_update' => [new OrderWebhookHandlerSpy('shipped', $log)],
        ]);

        self::assertSame([WebhookAction::StatusUpdate, WebhookAction::CreateShipment, WebhookAction::Delete], $handlers->subscribedActions());
        self::assertSame([], (new OrderWebhookHandlers([]))->subscribedActions());
    }
}
