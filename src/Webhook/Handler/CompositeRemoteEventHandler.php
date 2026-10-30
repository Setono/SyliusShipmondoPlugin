<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook\Handler;

use Setono\CompositeCompilerPass\CompositeService;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;

/**
 * @extends CompositeService<RemoteEventHandlerInterface>
 */
final class CompositeRemoteEventHandler extends CompositeService implements RemoteEventHandlerInterface
{
    public function handle(RemoteEvent $remoteEvent): void
    {
        foreach ($this->services as $service) {
            $service->handle($remoteEvent);
        }
    }
}
