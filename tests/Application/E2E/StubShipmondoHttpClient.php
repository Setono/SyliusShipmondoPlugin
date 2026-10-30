<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Application\E2E;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A PSR-18 HTTP client that answers the Shipmondo API calls with canned responses, so the Playwright
 * end-to-end tests (tests/Playwright) run against the real plugin and the real Shipmondo SDK without
 * Shipmondo credentials or network access. It is wired in only in the `e2e` environment
 * (config/services_e2e.yaml), where it replaces the transport of the `setono_sylius_shipmondo.client`.
 *
 * The response bodies live in the `shipmondo` directory next to this class. Every request is appended
 * as one JSON line to the log file, so the tests can assert what the plugin sent to Shipmondo.
 *
 * A test selects a scenario with the `shipmondo_stub` cookie on the browser request that triggers the
 * API call:
 *  - (no cookie): the happy path
 *  - `empty`: every list endpoint returns no items
 *  - `unauthorized`: every call fails with HTTP 401, like invalid credentials would
 *
 * Deleting a sales order (`DELETE /sales_orders/{id}`) answers by the id's prefix: an id starting with
 * 404 returns HTTP 404 (already deleted in Shipmondo), an id starting with 500 returns HTTP 500, and any
 * other id succeeds with HTTP 204.
 */
final class StubShipmondoHttpClient implements ClientInterface
{
    public const SCENARIO_COOKIE = 'shipmondo_stub';

    private const BASE_PATH = '/api/public/v3/';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly string $responsesDir,
        private readonly string $logFile,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $scenario = $this->getScenario();
        $this->log($request, $scenario);

        if ('unauthorized' === $scenario) {
            return self::json(401, ['error' => 'Invalid API credentials']);
        }

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, self::BASE_PATH)) {
            $path = substr($path, strlen(self::BASE_PATH));
        }

        $listing = 'empty' === $scenario ? '[]' : null;

        if ('GET' === $method) {
            switch ($path) {
                case 'payment_gateways':
                case 'shipment_templates':
                case 'webhooks':
                    return self::page($listing ?? $this->read($path));
                case 'pickup_points':
                    // the real endpoint is not paginated, so it sends no pagination headers
                    return self::raw(200, $listing ?? $this->read($path));
            }
        }

        if ('POST' === $method && 'webhooks' === $path) {
            return self::json(201, self::createdWebhook((string) $request->getBody()));
        }

        if ('DELETE' === $method && 1 === preg_match('#^webhooks/\d+$#', $path)) {
            return new Response(204);
        }

        if ('DELETE' === $method && 1 === preg_match('#^sales_orders/(\d+)$#', $path, $matches)) {
            return match (true) {
                str_starts_with($matches[1], '404') => self::json(404, ['error' => 'Not found']),
                str_starts_with($matches[1], '500') => self::json(500, ['error' => 'Internal server error']),
                default => new Response(204),
            };
        }

        return self::json(501, ['error' => sprintf('The Shipmondo stub does not handle %s %s', $method, $path)]);
    }

    private function getScenario(): ?string
    {
        $value = $this->requestStack->getMainRequest()?->cookies->get(self::SCENARIO_COOKIE);

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function read(string $name): string
    {
        $contents = file_get_contents(sprintf('%s/%s.json', $this->responsesDir, $name));
        if (false === $contents) {
            throw new \RuntimeException(sprintf('Could not read the Shipmondo stub response "%s"', $name));
        }

        return $contents;
    }

    /**
     * Shipmondo answers a POST /webhooks with the created webhook, echoing what was sent
     *
     * @return array<string, mixed>
     */
    private static function createdWebhook(string $requestBody): array
    {
        /** @var array<string, mixed> $sent */
        $sent = json_decode($requestBody, true, 512, \JSON_THROW_ON_ERROR);
        $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP');

        return [
            'id' => random_int(8_000_000, 8_999_999),
            'endpoint' => $sent['endpoint'] ?? null,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
            'name' => $sent['name'] ?? null,
            'action' => $sent['action'] ?? null,
            'resource_name' => $sent['resource_name'] ?? null,
        ];
    }

    /**
     * A single page of a paginated list endpoint. Shipmondo sends the pagination in headers
     */
    private static function page(string $body): ResponseInterface
    {
        /** @var list<mixed> $items */
        $items = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        return self::raw(200, $body, [
            'X-Current-Page' => '1',
            'X-Per-Page' => '20',
            'X-Total-Count' => (string) count($items),
            'X-Total-Pages' => '1',
        ]);
    }

    private static function json(int $status, mixed $data): ResponseInterface
    {
        return self::raw($status, json_encode($data, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function raw(int $status, string $body, array $headers = []): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, $body);
    }

    private function log(RequestInterface $request, ?string $scenario): void
    {
        $uri = $request->getUri();
        parse_str($uri->getQuery(), $query);
        $body = (string) $request->getBody();
        $decodedBody = '' === $body ? null : json_decode($body, true);
        if (is_array($decodedBody) && isset($decodedBody['key'])) {
            // the webhook signing key is a secret, keep it out of the log
            $decodedBody['key'] = '***';
        }

        $line = json_encode([
            'time' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'scenario' => $scenario,
            'method' => $request->getMethod(),
            'path' => $uri->getPath(),
            'query' => $query,
            'body' => $decodedBody,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        file_put_contents($this->logFile, $line . \PHP_EOL, \FILE_APPEND | \LOCK_EX);
    }
}
