<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Prophecy\Argument;
use Prophecy\Argument\Token\TokenInterface;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Setono\Shipmondo\Client\Client;

/**
 * Builds the real Shipmondo SDK client on top of a (prophesized) PSR-18 HTTP client, so tests exercise the SDK's
 * request building and response mapping while the HTTP boundary is stubbed.
 */
final class ShipmondoApi
{
    public static function client(HttpClientInterface $httpClient): Client
    {
        $psr17 = new Psr17Factory();

        return new Client('user', 'key', true, $httpClient, $psr17, $psr17);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function json(array $data, int $statusCode = 200): ResponseInterface
    {
        $psr17 = new Psr17Factory();

        return $psr17->createResponse($statusCode)->withBody($psr17->createStream(json_encode($data, \JSON_THROW_ON_ERROR)));
    }

    public static function empty(int $statusCode = 200): ResponseInterface
    {
        return (new Psr17Factory())->createResponse($statusCode);
    }

    /**
     * Matches a request by HTTP method and the path relative to the API root, e.g. ('GET', 'shipment_templates')
     */
    public static function request(string $method, string $path): TokenInterface
    {
        return Argument::that(
            static fn (RequestInterface $request): bool => $method === $request->getMethod() &&
                str_ends_with($request->getUri()->getPath(), '/api/public/v3/' . $path),
        );
    }
}
