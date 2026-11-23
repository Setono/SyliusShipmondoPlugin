<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

interface UnlinkedOrdersProviderInterface
{
    /**
     * The unlinked orders that are due a sales order lookup: those unlinked for at least ten minutes, whatever their
     * state in Sylius, cancelled included. Shipmondo lists a sales order it just created only after a short delay, so a
     * lookup any sooner could miss the sales order an upload created. The most recently unlinked come first
     *
     * @return iterable<array-key, OrderInterface>
     */
    public function getOrders(): iterable;
}
