<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook;

use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Setono\Doctrine\ORMTrait;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlers;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\RemoteEvent as BaseRemoteEvent;
use Webmozart\Assert\Assert;

/**
 * Routes each Shipmondo webhook about a sales order to the handlers subscribed to its action, on the Sylius order the
 * webhook belongs to, and flushes once they have all run.
 *
 * A webhook nothing subscribes to is ignored, which is what Shipmondo sends until the webhooks are registered again.
 * Nothing is caught: a handler that fails, or a flush that fails because something else changed the order meanwhile
 * (its version column), makes the webhook fail. Handled synchronously (the default), Shipmondo gets an error response
 * and sends the webhook again; routed to an async transport, Messenger retries it.
 */
final class WebhookConsumer implements ConsumerInterface
{
    use ORMTrait;

    public function __construct(
        private readonly OrderResolverInterface $orderResolver,
        private readonly OrderWebhookHandlers $handlers,
        private readonly LoggerInterface $logger,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * @param BaseRemoteEvent|RemoteEvent $event
     */
    public function consume(BaseRemoteEvent $event): void
    {
        Assert::isInstanceOf($event, RemoteEvent::class);

        if (WebhookResourceName::Orders !== $event->getResource()) {
            return;
        }

        $subscribedHandlers = $this->handlers->forAction($event->getAction());
        if ([] === $subscribedHandlers) {
            return;
        }

        $payload = $event->getPayload();
        $order = $this->orderResolver->resolveFromPayload($payload);
        if (null === $order) {
            $this->logger->info('No order is linked to the sales order {salesOrder} (order number {orderNumber}) of the Shipmondo webhook orders/{action}, so the webhook is ignored', [
                'salesOrder' => $payload['id'] ?? null,
                'orderNumber' => $payload['order_id'] ?? null,
                'action' => $event->getAction()->value,
            ]);

            return;
        }

        foreach ($subscribedHandlers as $handler) {
            $handler->handle($order, $event);
        }

        $this->getManager($order)->flush();
    }
}
