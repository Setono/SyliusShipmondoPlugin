<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

interface WebhookRegistrarInterface
{
    /**
     * Will register your shop with Shipmondos webhook service
     */
    public function register(): void;

    /**
     * Should return a version (string) that uniquely identifies the webhooks being registered.
     */
    public function getVersion(): string;
}
