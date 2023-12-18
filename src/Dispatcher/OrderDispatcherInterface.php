<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Dispatcher;

interface OrderDispatcherInterface
{
    /**
     * Dispatches eligible orders to Shipmondo
     */
    public function dispatch(): void;
}
