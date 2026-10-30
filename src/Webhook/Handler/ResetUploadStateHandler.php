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
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Reacts to a sales order being deleted in Shipmondo: the order no longer exists there, so its
 * Shipmondo upload state is reset to pending and its Shipmondo id is cleared, which makes the next
 * `upload-orders` run re-upload it.
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
        $order->setShipmondoId(null);

        $this->getManager($order)->flush();
    }
}
