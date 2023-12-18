<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

interface PreQualifiedDispatchableOrdersProviderInterface
{
    /**
     * @return iterable<array-key, OrderInterface>
     */
    public function getOrders(): iterable;
}
