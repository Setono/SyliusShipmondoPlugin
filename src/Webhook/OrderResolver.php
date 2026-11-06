<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;

final class OrderResolver implements OrderResolverInterface
{
    public function __construct(private readonly OrderRepositoryInterface $orderRepository)
    {
    }

    public function resolveFromPayload(array $payload): ?OrderInterface
    {
        $salesOrderId = $payload['id'] ?? null;
        if (!is_int($salesOrderId)) {
            return null;
        }

        $order = $this->orderRepository->findOneBy(['shipmondoId' => $salesOrderId]);

        return $order instanceof OrderInterface ? $order : null;
    }
}
