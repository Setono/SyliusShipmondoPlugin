<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Canceller;

use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderCanceller implements OrderCancellerInterface
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly WorkflowInterface $orderWorkflow,
    ) {
    }

    public function cancel(OrderInterface $order): ?bool
    {
        if (null !== $order->getShipmondoId()) {
            $handled = $this->commandBus->dispatch(new DeleteOrder($order))->last(HandledStamp::class);

            return null === $handled ? null : true === $handled->getResult();
        }

        if (!$this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_CANCEL)) {
            return null;
        }

        $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_CANCEL);

        return true;
    }
}
