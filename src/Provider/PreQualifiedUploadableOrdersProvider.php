<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Doctrine\ORM\EntityRepository;
use DoctrineBatchUtils\BatchProcessing\SelectBatchIteratorAggregate;
use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\SyliusShipmondoPlugin\Event\PreSelectPreQualifiedUploadableOrders;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;

final class PreQualifiedUploadableOrdersProvider implements PreQualifiedUploadableOrdersProviderInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface&EntityRepository $orderRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @return \Generator<array-key, OrderInterface>
     */
    public function getOrders(): \Generator
    {
        $qb = $this->orderRepository->createQueryBuilder('o')
            ->andWhere('o.shipmondoState = :shipmondoState')
            ->andWhere('o.state = :state')
            ->andWhere('o.checkoutState = :checkoutState')
            ->andWhere('o.paymentState IN (:paymentStates)')
            ->setParameter('shipmondoState', OrderInterface::SHIPMONDO_STATE_PENDING)
            ->setParameter('state', OrderInterface::STATE_NEW)
            ->setParameter('checkoutState', OrderCheckoutStates::STATE_COMPLETED)
            ->setParameter('paymentStates', [OrderPaymentStates::STATE_PAID, OrderPaymentStates::STATE_AUTHORIZED])
        ;

        $this->eventDispatcher->dispatch(new PreSelectPreQualifiedUploadableOrders($qb));

        /** @var SelectBatchIteratorAggregate<array-key, OrderInterface> $iterator */
        $iterator = SelectBatchIteratorAggregate::fromQuery($qb->getQuery(), 50);

        yield from $iterator;
    }
}
