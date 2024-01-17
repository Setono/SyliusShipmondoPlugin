<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

interface WebhookRegistrarInterface
{
    /**
     * Will register your shop with Shipmondos webhook service
     */
    public function register(): void;
}
