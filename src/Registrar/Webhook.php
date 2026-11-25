<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;

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
}
