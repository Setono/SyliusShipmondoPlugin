<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

interface UnlinkedOrdersProviderInterface
{
    /**
     * The unlinked orders that are due a sales order lookup: those unlinked for at least ten minutes, whatever their
     * state in Sylius, cancelled included. Shipmondo lists a sales order it just created only after a short delay, so a
     * lookup any sooner could miss the sales order an upload created. The ten minutes count from when the order became
     * unlinked ({@see OrderInterface::getShipmondoStateChangedAt()}), so a later change to the order, e.g. a cancel in
     * Sylius, doesn't put its lookup off. An unlinked order without that time became unlinked before the plugin
     * recorded it, so it is due. The most recently unlinked come first
     *
     * @return iterable<array-key, OrderInterface>
     */
    public function getOrders(): iterable;
}
