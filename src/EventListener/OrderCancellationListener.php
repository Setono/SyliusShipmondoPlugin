<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\EventListener;

use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * When a Sylius order is cancelled, cancel it in Shipmondo too ({@see OrderCancellerInterface}): an uploaded order's
 * sales order is deleted, and a flash message tells the user whether it was deleted, will be deleted (async
 * transport) or could not be deleted. A failed deletion never blocks the cancellation (see DeleteOrderHandler).
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
        private readonly OrderCancellerInterface $orderCanceller,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(OrderInterface $order): void
    {
        // only the deletion of a sales order is worth telling the user about
        $uploaded = null !== $order->getShipmondoId();

        $deleted = $this->orderCanceller->cancel($order);

        if (!$uploaded) {
            return;
        }

        match ($deleted) {
            // DeleteOrder was routed to an async transport, so the sales order is deleted later
            null => $this->addFlash('info', 'setono_sylius_shipmondo.order_will_be_deleted_in_shipmondo'),
            true => $this->addFlash('info', 'setono_sylius_shipmondo.order_deleted_in_shipmondo'),
            false => $this->addFlash('error', 'setono_sylius_shipmondo.order_not_deleted_in_shipmondo'),
        };
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
