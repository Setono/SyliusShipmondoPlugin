<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Setono\Shipmondo\Client\Client;

/**
 * Stands in for the Shipmondo API at the HTTP boundary: the real SDK client builds the requests and maps
 * the responses, but the PSR-18 transport is this stub, so nothing ever leaves the process.
 *
 * Responses are registered per "METHOD path" (the path relative to the API base, without the query string).
 * A request nobody registered a response for is recorded as unexpected and answered with an exception, so
 * a test can assert that only the intended calls were made.
 */
final class ShipmondoApiStub implements HttpClientInterface
{
    private readonly Psr17Factory $psr17;

    /** @var array<string, list<ResponseInterface>> */
    private array $responses = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<string> */
    private array $unexpectedRequests = [];

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    /**
     * A real SDK client that talks to this stub instead of the network
     */
    public function client(): Client
    {
        return new Client('functional-test', 'functional-test', true, $this, $this->psr17, $this->psr17);
    }

    /**
     * Answers the next matching request with the given response. Register a response several times to answer
     * several requests; the last registered response keeps answering once the others are used up.
     *
     * @param array<array-key, mixed>|null $json
     * @param array<string, string> $headers
     */
    public function respond(string $method, string $path, int $status = 200, ?array $json = [], array $headers = []): self
    {
        $response = $this->psr17->createResponse($status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        if (null !== $json) {
            $response = $response
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->psr17->createStream(json_encode($json, \JSON_THROW_ON_ERROR)))
            ;
        }

        $this->responses[self::key($method, $path)][] = $response;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        $key = self::key($request->getMethod(), self::path($request));
        if (!isset($this->responses[$key])) {
            $this->unexpectedRequests[] = $key;

            throw new \LogicException(sprintf('The functional tests did not expect a Shipmondo request to "%s"', $key));
        }

        $response = $this->responses[$key][0];
        if (count($this->responses[$key]) > 1) {
            array_shift($this->responses[$key]);
        }

        $response->getBody()->rewind();

        return $response;
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(?string $method = null, ?string $path = null): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (RequestInterface $request): bool => (null === $method || $method === $request->getMethod()) &&
                (null === $path || $path === self::path($request)),
        ));
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function jsonBody(RequestInterface $request): array
    {
        $body = (string) $request->getBody();
        $request->getBody()->rewind();

        /** @var array<array-key, mixed> $data */
        $data = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return list<string>
     */
    public function unexpectedRequests(): array
    {
        return $this->unexpectedRequests;
    }

    private static function key(string $method, string $path): string
    {
        return sprintf('%s %s', strtoupper($method), trim($path, '/'));
    }

    /**
     * The request path relative to the API base, e.g. "sales_orders/123"
     */
    private static function path(RequestInterface $request): string
    {
        return trim((string) preg_replace('#^/api/public/v3#', '', $request->getUri()->getPath()), '/');
    }
}
