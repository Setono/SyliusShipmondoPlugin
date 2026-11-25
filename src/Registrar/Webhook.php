<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\Shipmondo\Response\Webhook\Webhook as WebhookResponse;

/**
 * A webhook this shop wants Shipmondo to send it
 */
final readonly class Webhook
{
    public function __construct(
        public string $name,
        public string $endpoint,
        public string $key,
        public WebhookAction $action,
        public WebhookResourceName $resource,
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
