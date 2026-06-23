<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Setono\Doctrine\ORMTrait;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\OrderTransitions;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Reacts to a sales order being deleted in Shipmondo: cancels the Sylius order and resets its Shipmondo
 * upload state (the order no longer exists in Shipmondo).
 *
 * Shipmondo has no dedicated "cancel" action for sales orders — cancelling an order archives/deletes
 * it and fires the `orders/delete` webhook (the deleted order reports `order_status: "archived"`).
 */
final class CancelOrderHandler implements RemoteEventHandlerInterface
{
    use ORMTrait;

    public function __construct(
        private readonly OrderResolverInterface $orderResolver,
        private readonly StateMachineInterface $stateMachine,
        private readonly WorkflowInterface $orderWorkflow,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function handle(RemoteEvent $remoteEvent): void
    {
        if (WebhookResourceName::Orders !== $remoteEvent->getResource() || WebhookAction::Delete !== $remoteEvent->getAction()) {
            return;
        }

        $order = $this->orderResolver->resolveFromPayload($remoteEvent->getPayload());
        if (null === $order) {
            return;
        }

        $changed = false;

        if ($this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)) {
            $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
            $changed = true;
        }

        // the order no longer exists in Shipmondo, so reset its upload state back to pending
        if ($this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_RESET)) {
            $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_RESET);
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        $this->getManager($order)->flush();
    }
}
