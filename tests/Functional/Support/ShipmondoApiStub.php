<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Setono\SyliusShipmondoPlugin\DependencyInjection\SetonoSyliusShipmondoExtension;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tests\Setono\SyliusShipmondoPlugin\Support\NetworkException;

/**
 * Stands in for the Shipmondo API at the HTTP boundary: the plugin's real SDK clients build the requests and map
 * the responses, but the PSR-18 transport of the plugin's HTTP clients is this stub ({@see self::install()}), so
 * nothing ever leaves the process.
 *
 * Responses are registered per "METHOD path" (the path relative to the API base, without the query string).
 * A request nobody registered a response for is recorded as unexpected and answered with an exception, so
 * a test can assert that only the intended calls were made.
 */
final class ShipmondoApiStub
{
    /**
     * The plugin's HTTP clients, whose PSR-18 versions the stub replaces
     */
    private const HTTP_CLIENTS = [
        SetonoSyliusShipmondoExtension::HTTP_CLIENT,
        SetonoSyliusShipmondoExtension::PICKUP_POINTS_HTTP_CLIENT,
    ];

    private readonly Psr17Factory $psr17;

    /** @var array<string, list<ResponseInterface|null>> null fails to connect */
    private array $responses = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var \SplObjectStorage<RequestInterface, string> the HTTP client that sent each request */
    private \SplObjectStorage $httpClients;

    /** @var list<string> */
    private array $unexpectedRequests = [];

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
        $this->httpClients = new \SplObjectStorage();
    }

    /**
     * Replaces the PSR-18 versions of the plugin's HTTP clients with this stub, so the plugin's Shipmondo clients, as
     * the container builds them, send their requests here. Must happen before any service that depends on them is
     * instantiated
     */
    public function install(ContainerInterface $container): void
    {
        foreach (self::HTTP_CLIENTS as $httpClient) {
            $container->set('psr18.' . $httpClient, $this->httpClient($httpClient));
        }
    }

    /**
     * The plugin's HTTP client that sent the request, e.g. "setono_sylius_shipmondo.pickup_points_http_client"
     */
    public function httpClientOf(RequestInterface $request): string
    {
        return $this->httpClients[$request];
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

    /**
     * Answers the next matching request by failing to connect, as a network problem or a timeout does
     */
    public function failToConnect(string $method, string $path): self
    {
        $this->responses[self::key($method, $path)][] = null;

        return $this;
    }

    /**
     * The PSR-18 client that stands in for the given HTTP client of the plugin: it answers from this stub, which
     * records that the HTTP client sent the request
     */
    private function httpClient(string $httpClient): HttpClientInterface
    {
        return new class($this, $httpClient) implements HttpClientInterface {
            public function __construct(private readonly ShipmondoApiStub $stub, private readonly string $httpClient)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->stub->answer($request, $this->httpClient);
            }
        };
    }

    /**
     * Answers a request one of the plugin's HTTP clients sent
     *
     * @internal only for the PSR-18 clients {@see self::install()} installs
     */
    public function answer(RequestInterface $request, string $httpClient): ResponseInterface
    {
        $this->requests[] = $request;
        $this->httpClients[$request] = $httpClient;

        $key = self::key($request->getMethod(), self::path($request));
        if (!isset($this->responses[$key])) {
            $this->unexpectedRequests[] = $key;

            throw new \LogicException(sprintf('The functional tests did not expect a Shipmondo request to "%s"', $key));
        }

        $response = $this->responses[$key][0];
        if (count($this->responses[$key]) > 1) {
            array_shift($this->responses[$key]);
        }

        if (null === $response) {
            throw new NetworkException($request);
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
