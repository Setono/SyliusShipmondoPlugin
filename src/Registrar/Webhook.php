<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;

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
}
