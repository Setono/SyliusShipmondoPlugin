<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Uploader;

use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Setono\DoctrineObjectManagerTrait\ORM\ORMManagerTrait;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProviderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Exception\LogicException;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderUploader implements OrderUploaderInterface
{
    use ORMManagerTrait;

    public function __construct(
        private readonly PreQualifiedUploadableOrdersProviderInterface $preQualifiedUploadableOrdersProvider,
        private readonly MessageBusInterface $commandBus,
        private readonly WorkflowInterface $orderWorkflow,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function upload(): void
    {
        // todo: check for eligibility

        foreach ($this->preQualifiedUploadableOrdersProvider->getOrders() as $order) {
            try {
                $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_START_UPLOAD);
            } catch (LogicException) {
                continue;
            }

            try {
                $this->getManager($order)->flush();
            } catch (OptimisticLockException) {
                continue;
            }

            $this->commandBus->dispatch(new UploadOrder($order));
        }
    }
}
