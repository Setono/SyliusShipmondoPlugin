<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Linker;

use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * Settles unlinked orders by sales order lookups: Shipmondo may have a sales order for an unlinked order, but which is
 * unknown, so it is looked up by the exact order id the order's upload sent
 * ({@see \Setono\SyliusShipmondoPlugin\Provider\OrderIdProviderInterface}). The upload run looks up each order that is
 * due a lookup ({@see \Setono\SyliusShipmondoPlugin\Provider\UnlinkedOrdersProviderInterface})
 */
interface OrderLinkerInterface
{
    /**
     * Looks up the sales order of an unlinked order, and settles the order by the sales orders Shipmondo has with its
     * order id. One: the order is linked to it, and is uploaded. None: someone deleted it in Shipmondo, or the upload's
     * call that failed temporarily didn't create one, so the order goes back to pending, to be uploaded again. Several:
     * the order stays unlinked, since some may be another shop's on the same Shipmondo account, and an error names them.
     *
     * The order's row is locked and the order is read again before it is settled. An order that changed since it was
     * loaded, e.g. because another upload run looked it up, or that a guard on the order workflow holds back, is left as
     * it is, even when it is still unlinked. An order that is cancelled in Sylius becomes cancelled once Shipmondo has
     * no sales order for it: right away when none was found, and once its sales order is deleted when one was. An order
     * that isn't unlinked is left alone, and Shipmondo isn't searched.
     *
     * A failed search is logged, never thrown.
     *
     * @return ShipmondoFailure|null why Shipmondo couldn't be searched, which leaves the order unlinked for the next
     *                               upload run, or null otherwise, whatever the search found
     */
    public function link(OrderInterface $order): ?ShipmondoFailure;
}
