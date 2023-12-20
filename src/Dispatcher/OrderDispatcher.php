<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Dispatcher;

use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Setono\DoctrineObjectManagerTrait\ORM\ORMManagerTrait;
use Setono\SyliusShipmondoPlugin\Message\Command\DispatchOrder;
use Setono\SyliusShipmondoPlugin\Provider\PreQualifiedDispatchableOrdersProviderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Exception\LogicException;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderDispatcher implements OrderDispatcherInterface
{
    use ORMManagerTrait;

    public function __construct(
        private readonly PreQualifiedDispatchableOrdersProviderInterface $preQualifiedDispatchableOrdersProvider,
        private readonly MessageBusInterface $commandBus,
        private readonly WorkflowInterface $orderWorkflow,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function dispatch(): void
    {
        // todo: check for eligibility

        foreach ($this->preQualifiedDispatchableOrdersProvider->getOrders() as $order) {
            try {
                $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_START_DISPATCH);
            } catch (LogicException) {
                continue;
            }

            try {
                $this->getManager($order)->flush();
            } catch (OptimisticLockException) {
                continue;
            }

            $this->commandBus->dispatch(new DispatchOrder($order));
        }
    }
}
