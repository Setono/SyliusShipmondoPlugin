<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Setono\SyliusShipmondoPlugin\Support\NetworkException;

/**
 * A PSR-18 client that answers Shipmondo requests from a script, in order, and records them. Give it to
 * {@see ShipmondoApi::client()} to exercise the real SDK without the network.
 */
final class ScriptedHttpClient implements HttpClientInterface
{
    private readonly Psr17Factory $psr17;

    /** @var list<ResponseInterface|null> null fails to connect */
    private array $answers = [];

    /** @var list<RequestInterface> */
    private array $requests = [];

    public function __construct()
    {
        $this->psr17 = new Psr17Factory();
    }

    /**
     * @param array<array-key, mixed>|null $json
     */
    public function respond(int $statusCode, ?array $json = null, ?string $body = null): self
    {
        $response = $this->psr17->createResponse($statusCode);

        $body ??= null === $json ? null : json_encode($json, \JSON_THROW_ON_ERROR);
        if (null !== $body) {
            $response = $response->withBody($this->psr17->createStream($body));
        }

        $this->answers[] = $response;

        return $this;
    }

    public function failToConnect(): self
    {
        $this->answers[] = null;

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ([] === $this->answers) {
            throw new \LogicException(sprintf('The test did not expect the request %s %s', $request->getMethod(), (string) $request->getUri()));
        }

        $answer = array_shift($this->answers);

        return $answer ?? throw new NetworkException($request);
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }
}
