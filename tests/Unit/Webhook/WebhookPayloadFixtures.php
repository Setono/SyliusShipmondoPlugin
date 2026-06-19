<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook;

/**
 * Loads real Shipmondo webhook payloads captured from the sandbox.
 *
 * Each fixture is the resource object Shipmondo sends inside the webhook `data` envelope (i.e. what
 * the WebhookParser hands to the handlers after unwrapping), captured live from the sandbox:
 * `orders_create_shipment` (a fully shipped order), `orders_status_update` / `orders_create_fulfillment`
 * (an order that is fulfilled/packed but not yet shipped) and `orders_delete` (a deleted/archived order).
 */
final class WebhookPayloadFixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
        \assert(is_string($contents));

        /** @var array<string, mixed> $payload */
        $payload = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

        return $payload;
    }
}
