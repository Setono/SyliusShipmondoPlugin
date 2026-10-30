<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook;

use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Symfony\Component\RemoteEvent\RemoteEvent as BaseRemoteEvent;

/**
 * The Symfony remote event only carries the payload array, but we also need the resource and action
 * the webhook parser extracted from the request — typed as the SDK's enums.
 */
final class RemoteEvent extends BaseRemoteEvent
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        string $name,
        array $payload,
        private readonly WebhookResourceName $resource,
        private readonly WebhookAction $action,
    ) {
        // Shipmondo doesn't send a unique id, so we generate one
        parent::__construct($name, bin2hex(random_bytes(8)), $payload);
    }

    public function getResource(): WebhookResourceName
    {
        return $this->resource;
    }

    public function getAction(): WebhookAction
    {
        return $this->action;
    }
}
