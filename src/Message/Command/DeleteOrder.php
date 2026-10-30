<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\Command;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * Deletes an order's sales order in Shipmondo
 */
final class DeleteOrder implements CommandInterface
{
    /**
     * The order id
     */
    public int|string $order;

    public function __construct(mixed $order)
    {
        if ($order instanceof OrderInterface) {
            $order = $order->getId();
        }

        if (!is_int($order) && !is_string($order)) {
            throw new \InvalidArgumentException(sprintf('The order id must be an integer or a string, got "%s"', get_debug_type($order)));
        }

        $this->order = $order;
    }
}
