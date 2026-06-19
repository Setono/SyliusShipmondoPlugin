<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\RequestMatcher\HasQueryParameterRequestMatcher;
use Setono\SyliusShipmondoPlugin\RequestMatcher\IsShipmondoWebhookRequestMatcher;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent as BaseRemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class WebhookParser extends AbstractRequestParser
{
    public function __construct(private readonly string $webhooksKey)
    {
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new IsShipmondoWebhookRequestMatcher(),
            new HasQueryParameterRequestMatcher('resource'),
            new HasQueryParameterRequestMatcher('action'),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): BaseRemoteEvent
    {
        /** @var array{data: string} $body */
        $body = $request->toArray();

        try {
            $decoded = JWT::decode($body['data'], new Key($this->webhooksKey, 'HS256'));
        } catch (\Throwable $e) {
            throw new RejectWebhookException(message: $e->getMessage(), previous: $e);
        }

        // Shipmondo wraps the resource object (the sales order, shipment, ...) in a `data` envelope,
        // alongside `webhook` and `url` metadata. Decode to associative arrays and unwrap `data` so
        // handlers receive the resource object itself (e.g. $payload['id'], $payload['order_status']).
        $decoded = json_decode((string) json_encode($decoded), true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;

        return new RemoteEvent(
            'shipmondo.event',
            is_array($data) ? $data : [],
            (string) $request->query->get('resource'),
            (string) $request->query->get('action'),
        );
    }

    // Shipmondo expects HTTP 200 instead of HTTP 202
    public function createSuccessfulResponse(): Response
    {
        return new Response('OK');
    }
}
