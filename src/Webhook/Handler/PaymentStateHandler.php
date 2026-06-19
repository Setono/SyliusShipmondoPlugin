<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Doctrine\Persistence\ManagerRegistry;
use Setono\Doctrine\ORMTrait;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Keeps the Sylius order's payment state in sync with Shipmondo payment events.
 *
 * Orders are uploaded to Shipmondo while either `paid` or `authorized`, so an authorized payment can be
 * captured (or its authorization voided) later in Shipmondo:
 *
 * - `orders/payment_captured` → completes the Sylius payment(s), so the order's payment state becomes `paid`
 * - `orders/payment_voided` → cancels the order's payment state
 *
 * Capture is applied to the individual payments (so they end up `completed` and consistent), while a void
 * is applied to the order payment state machine directly: cancelling a payment makes Sylius spawn a
 * replacement payment (its "let the customer pay again" flow), which is not what a voided authorization
 * means here. Every transition is `can()`-guarded, so an already-paid order is a safe no-op.
 */
final class PaymentStateHandler implements RemoteEventHandlerInterface
{
    use ORMTrait;

    public function __construct(
        private readonly OrderResolverInterface $orderResolver,
        private readonly StateMachineInterface $stateMachine,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function handle(RemoteEvent $remoteEvent): void
    {
        if ('orders' !== $remoteEvent->getResource()) {
            return;
        }

        $action = $remoteEvent->getAction();
        if ('payment_captured' !== $action && 'payment_voided' !== $action) {
            return;
        }

        $order = $this->orderResolver->resolveFromPayload($remoteEvent->getPayload());
        if (null === $order) {
            return;
        }

        $applied = 'payment_captured' === $action ? $this->capture($order) : $this->void($order);
        if (!$applied) {
            return;
        }

        // The state machine callbacks resolve the order's payment state (and order state) on transition.
        $this->getManager($order)->flush();
    }

    private function capture(OrderInterface $order): bool
    {
        $applied = false;
        foreach ($order->getPayments() as $payment) {
            if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
                $applied = true;
            }
        }

        return $applied;
    }

    private function void(OrderInterface $order): bool
    {
        if (!$this->stateMachine->can($order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL)) {
            return false;
        }

        $this->stateMachine->apply($order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL);

        return true;
    }
}
