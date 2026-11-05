<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Setono\Shipmondo\Enum\WebhookAction;

/**
 * The webhook handlers of each action on the orders resource, in the order they run in. The container fills it once,
 * when it is built, from each handler's getSubscribedActions()
 * ({@see \Setono\SyliusShipmondoPlugin\DependencyInjection\Compiler\RegisterOrderWebhookHandlersPass}), and creates a
 * handler only when a webhook it handles arrives.
 */
final class OrderWebhookHandlers
{
    /**
     * @param array<string, iterable<int, OrderWebhookHandlerInterface>> $handlers the handlers of each action, by the action's value
     */
    public function __construct(private readonly array $handlers)
    {
    }

    /**
     * @return list<OrderWebhookHandlerInterface> in the order they run in
     */
    public function forAction(WebhookAction $action): array
    {
        return [...$this->handlers[$action->value] ?? []];
    }

    /**
     * @return list<WebhookAction> the actions at least one handler is subscribed to, in the order of the SDK's actions
     */
    public function subscribedActions(): array
    {
        return array_values(array_filter(
            WebhookAction::cases(),
            fn (WebhookAction $action): bool => isset($this->handlers[$action->value]),
        ));
    }
}
