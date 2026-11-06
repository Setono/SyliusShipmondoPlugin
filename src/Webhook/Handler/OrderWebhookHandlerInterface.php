<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Setono\Shipmondo\Enum\WebhookAction;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;

/**
 * A reaction to Shipmondo webhooks about sales orders (the orders resource), run on the Sylius order the webhook
 * belongs to.
 *
 * The actions it subscribes to are read once, when the container is built. The webhook consumer
 * ({@see \Setono\SyliusShipmondoPlugin\Webhook\WebhookConsumer}) calls it only for those actions, only once it has
 * found that order (the one linked to the sales order), and flushes once every handler has run. Webhook registration
 * subscribes to exactly the actions the handlers subscribe to, so run `setono:sylius-shipmondo:register-webhooks` again
 * after adding a handler for another action.
 *
 * Implementations are autoconfigured with the `setono_sylius_shipmondo.order_webhook_handler` tag; its priority
 * decides the order handlers subscribed to the same action run in.
 */
interface OrderWebhookHandlerInterface
{
    /**
     * @return list<WebhookAction> the actions on the orders resource this handler reacts to
     */
    public static function getSubscribedActions(): array;

    /**
     * @param RemoteEvent $webhook its payload is the sales order
     */
    public function handle(OrderInterface $order, RemoteEvent $webhook): void;
}
