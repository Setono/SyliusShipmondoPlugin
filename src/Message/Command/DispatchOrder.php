<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\Command;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

final class DispatchOrder implements CommandInterface
{
    /**
     * The order id
     */
    public mixed $order;

    /**
     * If the version is set, it will be used to check if the order has been updated since it was dispatched
     */
    public ?int $version = null;

    public function __construct(mixed $order, int $version = null)
    {
        if ($order instanceof OrderInterface) {
            $version = $order->getVersion();

            /** @var mixed $order */
            $order = $order->getId();
        }

        $this->order = $order;
        $this->version = $version;
    }
}
