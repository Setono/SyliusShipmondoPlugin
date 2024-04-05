<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

final class Webhook
{
    public function __construct(
        public readonly string $name,
        public readonly string $endpoint,
        public readonly string $key,
        public readonly string $action,
        public readonly string $resource,
    ) {
    }
}
