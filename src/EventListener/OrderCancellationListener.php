<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\EventListener;

use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * When a Sylius order is cancelled, delete its sales order in Shipmondo (if it was uploaded there) and
 * flash a message so the user knows whether it was deleted, will be deleted (async transport) or could
 * not be deleted. A failed deletion never blocks the cancellation (see DeleteOrderHandler).
 *
 * Sylius 1.14 runs the `sylius_order` state machine on winzou by default, but it is migrating to
 * Symfony Workflow — so we hook both backends: a winzou `after` callback on the `cancel` transition
 * calls {@see self::__invoke()}, while {@see self::onOrderCancelled()} listens to the Symfony Workflow
 * `workflow.sylius_order.completed.cancel` event. Only one fires for a given app, depending on which
 * state-machine adapter backs `sylius_order`.
 */
final class OrderCancellationListener
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(OrderInterface $order): void
    {
        if (null === $order->getShipmondoId()) {
            return;
        }

        $envelope = $this->commandBus->dispatch(new DeleteOrder($order));

        $handled = $envelope->last(HandledStamp::class);
        if (null === $handled) {
            // the command was routed to an async transport, so the sales order is deleted later
            $this->addFlash('info', 'setono_sylius_shipmondo.order_will_be_deleted_in_shipmondo');
        } elseif (true === $handled->getResult()) {
            $this->addFlash('info', 'setono_sylius_shipmondo.order_deleted_in_shipmondo');
        } else {
            $this->addFlash('error', 'setono_sylius_shipmondo.order_not_deleted_in_shipmondo');
        }
    }

    public function onOrderCancelled(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        if ($subject instanceof OrderInterface) {
            $this($subject);
        }
    }

    private function addFlash(string $type, string $message): void
    {
        try {
            $session = $this->requestStack->getSession();
        } catch (SessionNotFoundException) {
            // the order was cancelled outside an HTTP request (e.g. on the CLI) — nothing to flash
            return;
        }

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
