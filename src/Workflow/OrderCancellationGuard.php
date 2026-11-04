<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Workflow;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Ties the order's Shipmondo state to its cancellation in Sylius: only a cancelled order can be cancelled, and a
 * cancelled order is never reset to pending to be uploaded again
 */
final class OrderCancellationGuard implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            sprintf('workflow.%s.guard.%s', OrderWorkflow::NAME, OrderWorkflow::TRANSITION_CANCEL) => 'guardCancel',
            sprintf('workflow.%s.guard.%s', OrderWorkflow::NAME, OrderWorkflow::TRANSITION_RESET) => 'guardReset',
        ];
    }

    public function guardCancel(GuardEvent $event): void
    {
        if (!self::isCancelled($event)) {
            $event->setBlocked(true, 'Only an order that is cancelled in Sylius can be cancelled in Shipmondo');
        }
    }

    public function guardReset(GuardEvent $event): void
    {
        if (self::isCancelled($event)) {
            $event->setBlocked(true, 'A cancelled order is cancelled in Shipmondo, not reset to be uploaded again');
        }
    }

    private static function isCancelled(GuardEvent $event): bool
    {
        $order = $event->getSubject();

        return $order instanceof OrderInterface && OrderInterface::STATE_CANCELLED === $order->getState();
    }
}
