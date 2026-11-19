<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

/**
 * The webhooks were not registered, because Shipmondo can't reach the endpoint they would be sent to, e.g. at
 * localhost (see {@see WebhookHost}). Nothing was asked of Shipmondo, so the webhooks registered before are kept
 */
final class UnreachableWebhookEndpointException extends \RuntimeException
{
    public function __construct(public readonly string $endpoint)
    {
        parent::__construct(sprintf('The webhooks were not registered: Shipmondo can\'t reach their endpoint, %s.', $endpoint));
    }
}
