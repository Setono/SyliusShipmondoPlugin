<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Setono\Shipmondo\Enum\WebhookAction;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Reacts to a sales order being deleted in Shipmondo: the order is no longer uploaded, so it is reset to pending, which
 * makes the next upload run upload it again. An order that is cancelled in Sylius becomes cancelled right after
 * ({@see OrderCancellerInterface}), e.g. when its sales order couldn't be deleted as the order was cancelled, so an
 * admin deleted it in Shipmondo.
 *
 * Deleting a sales order in Shipmondo is intentionally *not* treated as a cancellation — cancelling an
 * order is a Sylius-side decision.
 */
final class SalesOrderDeletedHandler implements OrderWebhookHandlerInterface
{
    public function __construct(
        private readonly WorkflowInterface $orderWorkflow,
        private readonly OrderCancellerInterface $orderCanceller,
    ) {
    }

    public static function getSubscribedActions(): array
    {
        return [WebhookAction::Delete];
    }

    public function handle(OrderInterface $order, RemoteEvent $event): void
    {
        // Only react when the deleted sales order is the one this order is linked to. The order may have been found by
        // its order number, so a deleted duplicate (same order_id, another id) must not unlink the order, and neither
        // must the delete echoed back after our own DeleteOrder, which has already unlinked it.
        if ($order->getShipmondoId() !== ($event->getPayload()['id'] ?? null)) {
            return;
        }

        if (!$this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_RESET)) {
            return;
        }

        $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_RESET);
        $this->orderCanceller->cancel($order);
    }
}
