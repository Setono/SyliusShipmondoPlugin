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
        // Prefer the Shipmondo sales-order id (stored on the order when it was uploaded)
        $shipmondoId = $payload['id'] ?? null;
        if (is_int($shipmondoId)) {
            $order = $this->orderRepository->findOneBy(['shipmondoId' => $shipmondoId]);
            if ($order instanceof OrderInterface) {
                return $order;
            }
        }

        // Fall back to the order_id we sent to Shipmondo, which is the Sylius order number
        $number = $payload['order_id'] ?? null;
        if (is_string($number)) {
            $order = $this->orderRepository->findOneBy(['number' => $number]);
            if ($order instanceof OrderInterface) {
                return $order;
            }
        }

        return null;
    }
}
