<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\Command;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * Uploads an order to Shipmondo
 */
final class UploadOrder implements CommandInterface
{
    /**
     * The order id
     */
    public int|string $order;

    /**
     * If the version is set, it will be used to check if the order has been updated since it was triggered for upload
     */
    public ?int $version = null;

    public function __construct(mixed $order, ?int $version = null)
    {
        if ($order instanceof OrderInterface) {
            $version = $order->getVersion();
            $order = $order->getId();
        }

        if (!is_int($order) && !is_string($order)) {
            throw new \InvalidArgumentException(sprintf('The order id must be an integer or a string, got "%s"', get_debug_type($order)));
        }

        $this->order = $order;
        $this->version = $version;
    }
}
