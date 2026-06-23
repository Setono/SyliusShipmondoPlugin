<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\Shipmondo\Webhook\WebhookParser as ShipmondoWebhookParser;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\WebhookParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class WebhookParserTest extends TestCase
{
    // HS256 keys must be at least 32 bytes (the SDK enforces this)
    private const KEY = 'test-webhook-key-with-at-least-32-bytes';

    /**
     * @test
     */
    public function it_maps_a_verified_webhook_to_a_remote_event(): void
    {
        // a real Shipmondo webhook: the resource object inside a signed `data` envelope, plus SMD-* headers
        $salesOrder = WebhookPayloadFixtures::load('orders_delete');
        $request = self::request('Orders', 'delete', [
            'webhook' => 'Setono Sylius Shipmondo Plugin - [orders:delete]',
            'data' => $salesOrder,
            'url' => 'https://example.com/webhook/shipmondo',
        ]);

        $event = self::parser()->parse($request, self::KEY);

        self::assertInstanceOf(RemoteEvent::class, $event);
        self::assertSame(WebhookResourceName::Orders, $event->getResource());
        self::assertSame(WebhookAction::Delete, $event->getAction());

        // handlers receive the unwrapped resource object
        $payload = $event->getPayload();
        self::assertArrayNotHasKey('data', $payload);
        self::assertSame($salesOrder['id'], $payload['id']);
        self::assertSame($salesOrder['order_id'], $payload['order_id']);
    }

    /**
     * @test
     */
    public function it_rejects_a_webhook_signed_with_the_wrong_key(): void
    {
        $request = self::request('Orders', 'delete', ['data' => []], signingKey: 'a-different-key-of-at-least-32-bytes!!!');

        $this->expectException(RejectWebhookException::class);

        self::parser()->parse($request, self::KEY);
    }

    private static function parser(): WebhookParser
    {
        return new WebhookParser(new ShipmondoWebhookParser(), self::KEY);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private static function request(string $resourceType, string $action, array $envelope, string $signingKey = self::KEY): Request
    {
        $jwt = JWT::encode($envelope, $signingKey, 'HS256');

        return Request::create(
            '/webhook/shipmondo',
            'POST',
            server: [
                'HTTP_SMD_RESOURCE_TYPE' => $resourceType,
                'HTTP_SMD_ACTION' => $action,
            ],
            content: (string) json_encode(['data' => $jwt]),
        );
    }
}
