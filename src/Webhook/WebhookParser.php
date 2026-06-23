<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Webhook;

use Setono\Shipmondo\Webhook\WebhookParserInterface as ShipmondoWebhookParserInterface;
use Setono\SyliusShipmondoPlugin\RequestMatcher\IsShipmondoWebhookRequestMatcher;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent as BaseRemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

/**
 * Adapts an incoming Shipmondo webhook (verified and parsed by the SDK) to a Symfony remote event.
 *
 * The SDK's parser verifies the HS256 signature, reads the `SMD-*` headers and unwraps the `data`
 * envelope; this class only maps the resulting event onto the plugin's {@see RemoteEvent}.
 */
final class WebhookParser extends AbstractRequestParser
{
    public function __construct(
        private readonly ShipmondoWebhookParserInterface $shipmondoWebhookParser,
        private readonly string $webhooksKey,
    ) {
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new IsShipmondoWebhookRequestMatcher(),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): BaseRemoteEvent
    {
        $headers = [];
        foreach ($request->headers->keys() as $name) {
            $headers[$name] = $request->headers->get($name) ?? '';
        }

        try {
            $event = $this->shipmondoWebhookParser->parsePayload((string) $request->getContent(), $headers, $this->webhooksKey);
        } catch (\Throwable $e) {
            throw new RejectWebhookException(message: $e->getMessage(), previous: $e);
        }

        return new RemoteEvent(
            'shipmondo.event',
            $event->data,
            $event->resourceType,
            $event->action,
        );
    }

    // Shipmondo expects HTTP 200 instead of HTTP 202
    public function createSuccessfulResponse(): Response
    {
        return new Response('OK');
    }
}
