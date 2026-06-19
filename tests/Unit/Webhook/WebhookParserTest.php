<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\WebhookParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class WebhookParserTest extends TestCase
{
    private const SECRET = 'webhooks-secret';

    /**
     * @test
     */
    public function it_unwraps_the_data_envelope_and_reads_resource_and_action_from_the_query(): void
    {
        // a real Shipmondo webhook wraps the resource object in a `data` envelope alongside metadata
        $salesOrder = WebhookPayloadFixtures::load('orders_delete');
        $request = self::request('orders', 'delete', [
            'webhook' => 'Setono Sylius Shipmondo Plugin - [orders:delete]',
            'data' => $salesOrder,
            'url' => 'https://example.com/webhook/shipmondo?resource=orders&action=delete',
        ]);

        $event = (new WebhookParser(self::SECRET))->parse($request, self::SECRET);

        self::assertInstanceOf(RemoteEvent::class, $event);
        self::assertSame('orders', $event->getResource());
        self::assertSame('delete', $event->getAction());

        // the payload handlers receive is the unwrapped resource object, not the envelope
        $payload = $event->getPayload();
        self::assertArrayNotHasKey('data', $payload);
        self::assertArrayNotHasKey('webhook', $payload);
        self::assertSame($salesOrder['id'], $payload['id']);
        self::assertSame($salesOrder['order_id'], $payload['order_id']);
    }

    /**
     * @test
     */
    public function it_rejects_a_body_whose_jwt_is_not_signed_with_the_webhooks_key(): void
    {
        $request = self::request('orders', 'delete', ['data' => []], signingKey: 'a-different-key');

        $this->expectException(RejectWebhookException::class);

        (new WebhookParser(self::SECRET))->parse($request, self::SECRET);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function request(string $resource, string $action, array $envelope, string $signingKey = self::SECRET): Request
    {
        $jwt = JWT::encode($envelope, $signingKey, 'HS256');

        return Request::create(
            sprintf('/webhook/shipmondo?resource=%s&action=%s', $resource, $action),
            'POST',
            content: (string) json_encode(['data' => $jwt]),
        );
    }
}
