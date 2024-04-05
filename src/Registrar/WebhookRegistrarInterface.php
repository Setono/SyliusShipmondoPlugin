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
     * Should return a hash (string) that uniquely identifies the webhooks being registered.
     * The easiest approach is a hash of the concrete class
     */
    public function getHash(): string;
}
