<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

interface WebhookRegistrarInterface
{
    /**
     * Will register your shop with Shipmondos webhook service
     *
     * @throws UnreachableWebhookEndpointException when Shipmondo can't reach the webhooks' endpoint, before anything
     *                                             is registered
     */
    public function register(): void;

    /**
     * Will return a list of webhooks that this registrar will register
     *
     * @return iterable<Webhook>
     */
    public function getWebhooks(): iterable;

    /**
     * Should return a version (string) that uniquely identifies the webhooks being registered
     */
    public function getVersion(): string;
}
