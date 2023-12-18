<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Doctrine\ORM\QueryBuilder;

/**
 * This event is fired when selecting the pre-qualified dispatchable orders.
 * Listen to this event to filter orders that are considered pre-qualified dispatchable orders.
 */
final class PreSelectPreQualifiedDispatchableOrders
{
    public function __construct(public readonly QueryBuilder $queryBuilder)
    {
    }
}
