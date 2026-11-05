<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Setono\Shipmondo\Enum\WebhookAction;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Payment\PaymentTransitions;

/**
 * Keeps the Sylius order's payment state in step with Shipmondo's payment webhooks.
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
 * means here. Every transition is `can()`-guarded, so an already-paid order is a safe no-op. The state
 * machine callbacks resolve the order's payment state (and order state) on transition.
 */
final class SalesOrderPaymentHandler implements OrderWebhookHandlerInterface
{
    public function __construct(private readonly StateMachineInterface $stateMachine)
    {
    }

    public static function getSubscribedActions(): array
    {
        return [WebhookAction::PaymentCaptured, WebhookAction::PaymentVoided];
    }

    public function handle(OrderInterface $order, RemoteEvent $webhook): void
    {
        if (WebhookAction::PaymentCaptured === $webhook->getAction()) {
            $this->capture($order);

            return;
        }

        $this->void($order);
    }

    private function capture(OrderInterface $order): void
    {
        foreach ($order->getPayments() as $payment) {
            if ($this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            }
        }
    }

    private function void(OrderInterface $order): void
    {
        if ($this->stateMachine->can($order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL)) {
            $this->stateMachine->apply($order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL);
        }
    }
}
