<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Canceller;

use Psr\Log\LoggerInterface;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderCanceller implements OrderCancellerInterface
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly WorkflowInterface $orderWorkflow,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function cancel(OrderInterface $order): Cancellation
    {
        if (OrderInterface::STATE_CANCELLED !== $order->getState()) {
            return Cancellation::NotCancelled;
        }

        return match ($order->getShipmondoState()) {
            OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO => $this->deleteSalesOrder($order),
            OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO => Cancellation::LeftToUpload,
            OrderInterface::SHIPMONDO_STATE_UNLINKED => $this->leaveUnlinked($order),
            default => $this->cancelRightAway($order),
        };
    }

    /**
     * Shipmondo most likely has a sales order for the order, but which is unknown, so it can't be deleted, and the
     * order can't become cancelled: someone has to cancel the sales order in Shipmondo
     */
    private function leaveUnlinked(OrderInterface $order): Cancellation
    {
        $this->logger->error('The order {order} was cancelled, but it is unlinked: Shipmondo most likely has a sales order for it, but which is unknown, so it can\'t be deleted. Find the sales order in Shipmondo and cancel it there', [
            'order' => $order->getNumber(),
        ]);

        return Cancellation::LeftUnlinked;
    }

    private function deleteSalesOrder(OrderInterface $order): Cancellation
    {
        $handled = $this->commandBus->dispatch(new DeleteOrder($order), [new DispatchAfterCurrentBusStamp()])->last(HandledStamp::class);

        if (null === $handled) {
            return Cancellation::SalesOrderDeletedLater;
        }

        return true === $handled->getResult() ? Cancellation::SalesOrderDeleted : Cancellation::SalesOrderNotDeleted;
    }

    private function cancelRightAway(OrderInterface $order): Cancellation
    {
        if ($this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_CANCEL)) {
            $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_CANCEL);
        }

        return OrderInterface::SHIPMONDO_STATE_CANCELLED === $order->getShipmondoState() ? Cancellation::Cancelled : Cancellation::NotCancelled;
    }
}
