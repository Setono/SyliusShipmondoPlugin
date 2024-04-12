<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Event;

use Doctrine\ORM\QueryBuilder;

/**
 * This event is fired when the query builder for pre-qualified uploadable orders has been created.
 * Listen to this event if you want to filter orders that are considered pre-qualified uploadable orders.
 */
final class PreQualifiedUploadableOrdersQueryBuilderCreatedEvent
{
    public function __construct(public readonly QueryBuilder $queryBuilder)
    {
    }
}
