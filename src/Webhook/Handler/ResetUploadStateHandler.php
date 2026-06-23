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

        $order = $this->orderResolver->resolveFromPayload($remoteEvent->getPayload());
        if (null === $order) {
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
