<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Doctrine\ORM\QueryBuilder;

/**
 * This event is fired when selecting the pre-qualified uploadable orders.
 * Listen to this event to filter orders that are considered pre-qualified uploadable orders.
 */
final class PreSelectPreQualifiedUploadableOrders
{
    public function __construct(public readonly QueryBuilder $queryBuilder)
    {
    }
}
