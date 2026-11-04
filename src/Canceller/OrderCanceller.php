<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Canceller;

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
            default => $this->cancelRightAway($order),
        };
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
