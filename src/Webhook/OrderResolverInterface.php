<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

interface OrderResolverInterface
{
    /**
     * Resolves the Sylius order linked to the sales order a Shipmondo webhook is about, or null if no order is.
     *
     * It never falls back to the order number: another shop on the same Shipmondo account numbers its orders the same
     * way, so a sales order no order is linked to belongs to none, even when its order number matches one. That also
     * covers a deleted duplicate of an order and the delete echoed back after the plugin deleted a sales order itself,
     * which has already unlinked the order.
     *
     * @param array<array-key, mixed> $payload the decoded Shipmondo sales-order payload
     */
    public function resolveFromPayload(array $payload): ?OrderInterface;
}
