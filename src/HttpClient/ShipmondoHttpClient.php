<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\HttpClient;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The HTTP client the plugin's Shipmondo client sends through. It sends a pickup point search, which a customer waits
 * for in checkout, through the pickup points HTTP client with its shorter time limits, and every other call through
 * the plugin's HTTP client.
 *
 * Both are the PSR-18 versions of framework scoped HTTP clients the plugin prepends, so a shop changes their limits in
 * its framework config.
 */
final class ShipmondoHttpClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly ClientInterface $pickupPointsHttpClient,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $httpClient = str_ends_with($request->getUri()->getPath(), '/pickup_points') ? $this->pickupPointsHttpClient : $this->httpClient;

        return $httpClient->sendRequest($request);
    }
}
