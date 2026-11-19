<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Support;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The transport under every Symfony HTTP client of the test application in the test environment
 * (`framework.http_client.mock_response_factory`), so no test reaches the network.
 *
 * The functional and browser tests replace the PSR-18 versions of the plugin's HTTP clients with
 * {@see ShipmondoApiStub}, so no request of the plugin's gets here. It fails every request, unless a test told it to
 * answer them ({@see self::answerWith()}). It records each request with the options the HTTP client sent it with,
 * e.g. the time limits of the plugin's scoped HTTP clients.
 */
final class HttpTransportStub
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    private ?MockResponse $response = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

        if (null === $this->response) {
            // as an unreachable network fails, so the application handles it as it would without one, e.g. Sylius's
            // admin, which asks Sylius for its latest version
            throw new TransportException(sprintf('The tests must not reach the network, but a request to "%s" was sent', $url));
        }

        return clone $this->response;
    }

    /**
     * Answers every request from now on with the given response
     */
    public function answerWith(MockResponse $response): void
    {
        $this->response = $response;
    }

    /**
     * @return list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
