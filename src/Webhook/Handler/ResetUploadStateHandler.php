<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Setono\Doctrine\ORMTrait;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
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
final class ResetUploadStateHandler implements RemoteEventHandlerInterface
{
    use ORMTrait;

    public function __construct(
        private readonly OrderResolverInterface $orderResolver,
        private readonly WorkflowInterface $orderWorkflow,
        private readonly OrderCancellerInterface $orderCanceller,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function handle(RemoteEvent $remoteEvent): void
    {
        if (WebhookResourceName::Orders !== $remoteEvent->getResource() || WebhookAction::Delete !== $remoteEvent->getAction()) {
            return;
        }

        $payload = $remoteEvent->getPayload();
        $shipmondoId = $payload['id'] ?? null;
        if (!is_int($shipmondoId)) {
            return;
        }

        // Only react when the deleted sales order is the one this order is linked to. The resolver falls back
        // to the order number, so a deleted duplicate (same order_id, another id) must not unlink the order,
        // and neither must the delete echoed back after our own DeleteOrder, which has already cleared the id.
        $order = $this->orderResolver->resolveFromPayload($payload);
        if (null === $order || $order->getShipmondoId() !== $shipmondoId) {
            return;
        }

        if (!$this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_RESET)) {
            return;
        }

        $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_RESET);
        $this->orderCanceller->cancel($order);

        $this->getManager($order)->flush();
    }
}
