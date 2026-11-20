<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\HttpClient;

use PHPUnit\Framework\TestCase;
use Setono\Shipmondo\Request\PickupPointSearch;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\HttpClient\ShipmondoHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;

final class ShipmondoHttpClientTest extends TestCase
{
    private ScriptedHttpClient $httpClient;

    private ScriptedHttpClient $pickupPointsHttpClient;

    protected function setUp(): void
    {
        $this->httpClient = new ScriptedHttpClient();
        $this->pickupPointsHttpClient = new ScriptedHttpClient();
    }

    /**
     * @test
     */
    public function it_sends_a_pickup_point_search_through_the_pickup_points_http_client(): void
    {
        $this->pickupPointsHttpClient->respond(200, []);

        $this->client()->pickupPoints()->search(new PickupPointSearch(carrierCode: 'gls', countryCode: 'DK', zipCode: '8000'));

        self::assertSame(['GET pickup_points'], $this->pickupPointsHttpClient->requestLines());
        self::assertSame([], $this->httpClient->requestLines());
    }

    /**
     * @test
     */
    public function it_sends_every_other_call_through_the_plugins_http_client(): void
    {
        $this->httpClient
            ->respond(201, ['id' => 1001])
            ->respond(200, ['id' => 1001])
            ->respond(204)
        ;

        $client = $this->client();
        $client->salesOrders()->create(new SalesOrderRequest(orderId: '000001001'));
        $client->salesOrders()->getById(1001);
        $client->salesOrders()->delete(1001);

        self::assertSame(['POST sales_orders', 'GET sales_orders/1001', 'DELETE sales_orders/1001'], $this->httpClient->requestLines());
        self::assertSame([], $this->pickupPointsHttpClient->requestLines());
    }

    private function client(): \Setono\Shipmondo\Client\Client
    {
        return ShipmondoApi::client(new ShipmondoHttpClient($this->httpClient, $this->pickupPointsHttpClient));
    }
}
