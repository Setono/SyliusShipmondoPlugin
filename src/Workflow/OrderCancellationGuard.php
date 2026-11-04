<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Workflow;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Lets an order's Shipmondo state become cancelled only when the order is cancelled in Sylius
 */
final class OrderCancellationGuard implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            sprintf('workflow.%s.guard.%s', OrderWorkflow::NAME, OrderWorkflow::TRANSITION_CANCEL) => 'guardCancel',
        ];
    }

    public function guardCancel(GuardEvent $event): void
    {
        $order = $event->getSubject();

        if (!$order instanceof OrderInterface || OrderInterface::STATE_CANCELLED !== $order->getState()) {
            $event->setBlocked(true, 'The order is not cancelled in Sylius');
        }
    }
}
