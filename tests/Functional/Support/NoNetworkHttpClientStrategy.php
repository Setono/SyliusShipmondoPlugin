<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Support;

use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A safety net for the functional tests: the plugin's Shipmondo client discovers its PSR-18 HTTP client
 * through php-http/discovery. The tests replace that client with {@see ShipmondoApiStub}, but should a
 * client ever be built without the stub, this strategy makes discovery hand out a client that refuses to
 * send anything instead of a real HTTP client that would call the Shipmondo API.
 */
final class NoNetworkHttpClientStrategy implements DiscoveryStrategy
{
    /**
     * @return list<array{class: \Closure(): ClientInterface}>
     */
    public static function getCandidates($type): array
    {
        if (ClientInterface::class !== $type) {
            return [];
        }

        return [[
            'class' => static fn (): ClientInterface => new class() implements ClientInterface {
                public function sendRequest(RequestInterface $request): ResponseInterface
                {
                    throw new \LogicException(sprintf(
                        'The functional tests must not reach the network, but a request to "%s" was sent',
                        (string) $request->getUri(),
                    ));
                }
            },
        ]];
    }
}
