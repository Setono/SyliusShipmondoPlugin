<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Doctrine\ORM\EntityRepository;
use DoctrineBatchUtils\BatchProcessing\SelectBatchIteratorAggregate;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;

/**
 * Tells how long an order has been unlinked by when its Shipmondo state last changed, which for an unlinked order is
 * when it became unlinked. Other changes of the order, e.g. a cancel in Sylius, don't put its lookup off. An unlinked
 * order without that time became unlinked before the plugin recorded it, so its lookup is due
 */
final readonly class UnlinkedOrdersProvider implements UnlinkedOrdersProviderInterface
{
    /**
     * How long an order is unlinked before its sales order is looked up
     */
    public const LOOKUP_DELAY = '10 minutes';

    /**
     * @param OrderRepositoryInterface&EntityRepository<OrderInterface> $orderRepository
     */
    public function __construct(private OrderRepositoryInterface&EntityRepository $orderRepository)
    {
    }

    /**
     * @return \Generator<array-key, OrderInterface>
     */
    public function getOrders(): \Generator
    {
        $query = $this->orderRepository->createQueryBuilder('o')
            ->andWhere('o.shipmondoState = :shipmondoState')
            ->andWhere('(o.shipmondoStateChangedAt IS NULL OR o.shipmondoStateChangedAt <= :unlinkedBefore)')
            ->setParameter('shipmondoState', OrderInterface::SHIPMONDO_STATE_UNLINKED)
            ->setParameter('unlinkedBefore', new \DateTimeImmutable('-' . self::LOOKUP_DELAY))
            // newest first: an order that stays unlinked, e.g. one with several sales orders, can't hold the others back
            // when a rate limit stops the lookups
            ->orderBy('o.shipmondoStateChangedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->getQuery()
        ;

        /** @var SelectBatchIteratorAggregate<array-key, OrderInterface> $iterator */
        $iterator = SelectBatchIteratorAggregate::fromQuery($query, 50);

        yield from $iterator;
    }
}
