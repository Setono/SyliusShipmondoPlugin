<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

interface OrderResolverInterface
{
    /**
     * Resolves the Sylius order a Shipmondo webhook refers to, or null if it can't be found.
     *
     * @param array<array-key, mixed> $payload the decoded Shipmondo sales-order payload
     */
    public function resolveFromPayload(array $payload): ?OrderInterface;
}
