<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;

interface RemoteEventHandlerInterface
{
    /**
     * Reacts to an incoming Shipmondo webhook. Implementations decide (based on the event's resource
     * and action) whether they apply, and no-op otherwise.
     */
    public function handle(RemoteEvent $remoteEvent): void;
}
