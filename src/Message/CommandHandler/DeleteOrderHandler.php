<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\CommandHandler;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\WorkflowInterface;
use Webmozart\Assert\Assert;

/**
 * Deletes an order's sales order in Shipmondo. Once it is gone, the order is no longer uploaded, so it is reset to
 * pending, and then becomes cancelled if it is cancelled in Sylius ({@see OrderCancellerInterface}).
 *
 * A sales order Shipmondo doesn't find is gone already. Any other failure throws, and the order stays uploaded and linked
 * to the sales order: a failure expected to pass as it is, so a worker retries it with its transport's retry strategy,
 * and any other as an UnrecoverableMessageHandlingException, so it goes to the failure transport. Handled synchronously,
 * inside the Sylius order cancellation, the failure must not fail the cancellation, so
 * {@see \Setono\SyliusShipmondoPlugin\Message\Middleware\AbsorbDeleteOrderFailureMiddleware} absorbs it and reports the
 * sales order as not deleted.
 *
 * Returns true, which the dispatcher reads as "the sales order is gone".
 */
final class DeleteOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ClientInterface $shipmondoClient,
        private readonly WorkflowInterface $orderWorkflow,
        private readonly OrderCancellerInterface $orderCanceller,
    ) {
    }

    public function __invoke(DeleteOrder $message): bool
    {
        $order = $this->orderRepository->find($message->order);
        if (null === $order) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s does not exist', $message->order));
        }

        Assert::isInstanceOf($order, OrderInterface::class);

        $shipmondoId = $order->getShipmondoId();
        if (null === $shipmondoId) {
            return true;
        }

        try {
            $this->shipmondoClient->salesOrders()->delete($shipmondoId);
        } catch (\Throwable $e) {
            match (ShipmondoFailure::of($e)->kind) {
                // the sales order was already deleted in Shipmondo, which is what we wanted
                ShipmondoFailureKind::NotFound => null,
                ShipmondoFailureKind::TemporaryFailure, ShipmondoFailureKind::RateLimited => throw $e,
                default => throw new UnrecoverableMessageHandlingException(sprintf(
                    'Shipmondo did not delete the sales order %d of the order %s, and trying again will not change that',
                    $shipmondoId,
                    $order->getNumber(),
                ), previous: $e),
            };
        }

        if ($this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_RESET)) {
            $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_RESET);
            $this->orderCanceller->cancel($order);
        }

        return true;
    }
}
