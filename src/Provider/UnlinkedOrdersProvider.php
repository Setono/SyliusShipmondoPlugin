<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Doctrine\ORM\EntityRepository;
use DoctrineBatchUtils\BatchProcessing\SelectBatchIteratorAggregate;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;

/**
 * Tells how long an order has been unlinked by its updatedAt, which every change of its Shipmondo state, and so the
 * one into unlinked, moves. Any later change of the order moves it too, which only puts its lookup off
 */
final class UnlinkedOrdersProvider implements UnlinkedOrdersProviderInterface
{
    /**
     * How long an order is unlinked before its sales order is looked up
     */
    public const LOOKUP_DELAY = '10 minutes';

    /**
     * @param OrderRepositoryInterface&EntityRepository<OrderInterface> $orderRepository
     */
    public function __construct(private readonly OrderRepositoryInterface&EntityRepository $orderRepository)
    {
    }

    /**
     * @return \Generator<array-key, OrderInterface>
     */
    public function getOrders(): \Generator
    {
        $query = $this->orderRepository->createQueryBuilder('o')
            ->andWhere('o.shipmondoState = :shipmondoState')
            ->andWhere('o.updatedAt <= :unlinkedBefore')
            ->setParameter('shipmondoState', OrderInterface::SHIPMONDO_STATE_UNLINKED)
            ->setParameter('unlinkedBefore', new \DateTimeImmutable('-' . self::LOOKUP_DELAY))
            // newest first: an order that stays unlinked, e.g. one with several sales orders, can't hold the others back
            // when a rate limit stops the lookups
            ->orderBy('o.updatedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
        ;

        /** @var SelectBatchIteratorAggregate<array-key, OrderInterface> $iterator */
        $iterator = SelectBatchIteratorAggregate::fromQuery($query, 50);

        yield from $iterator;
    }
}
