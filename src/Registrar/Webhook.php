<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\Shipmondo\Response\Webhook\Webhook as WebhookResponse;

/**
 * A webhook this shop wants Shipmondo to send it
 */
final class Webhook
{
    public function __construct(
        public readonly string $name,
        public readonly string $endpoint,
        public readonly string $key,
        public readonly WebhookAction $action,
        public readonly WebhookResourceName $resource,
    ) {
    }

    /**
     * Whether the webhook Shipmondo lists is this one, and active. Shipmondo doesn't list a webhook's key
     */
    public function isListedAs(WebhookResponse $webhook): bool
    {
        return $webhook->active &&
            $webhook->name === $this->name &&
            $webhook->endpoint === $this->endpoint &&
            $webhook->action === $this->action &&
            $webhook->resourceName === $this->resource;
    }
}
