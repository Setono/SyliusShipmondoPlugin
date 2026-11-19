<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\HttpClient;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Setono\Shipmondo\Client\Client;
use Setono\SyliusShipmondoPlugin\DependencyInjection\SetonoSyliusShipmondoExtension;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\HttpTransportStub;
use Webmozart\Assert\Assert;

/**
 * The time limits of the plugin's HTTP clients, as the test application's container builds them, on what they send to
 * Shipmondo. The requests go to the transport under every HTTP client in the tests ({@see HttpTransportStub}), which
 * records the options each was sent with
 */
final class ShipmondoHttpClientsTest extends KernelTestCase
{
    private HttpTransportStub $transport;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();

        $transport = self::getContainer()->get(HttpTransportStub::class);
        Assert::isInstanceOf($transport, HttpTransportStub::class);
        $transport->answerWith(new MockResponse('[]', ['response_headers' => ['Content-Type' => 'application/json', 'X-Total-Pages' => '1']]));
        $this->transport = $transport;
    }

    /**
     * @test
     *
     * @dataProvider shipmondoHosts
     */
    public function the_plugins_http_client_gives_up_on_a_request_to_shipmondo_after_10_seconds_without_data_or_30_in_all(string $host): void
    {
        $options = $this->send(SetonoSyliusShipmondoExtension::HTTP_CLIENT, $host);

        self::assertSame(10.0, $options['timeout']);
        self::assertSame(30.0, $options['max_duration']);
    }

    /**
     * @test
     *
     * @dataProvider shipmondoHosts
     */
    public function the_pickup_points_http_client_gives_up_after_5_seconds_without_data_or_10_in_all(string $host): void
    {
        $options = $this->send(SetonoSyliusShipmondoExtension::PICKUP_POINTS_HTTP_CLIENT, $host);

        self::assertSame(5.0, $options['timeout']);
        self::assertSame(10.0, $options['max_duration']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shipmondoHosts(): iterable
    {
        yield 'production' => ['app.shipmondo.com'];
        yield 'sandbox' => ['sandbox.shipmondo.com'];
    }

    /**
     * @test
     *
     * @dataProvider otherHosts
     */
    public function the_limits_apply_to_shipmondo_only(string $host): void
    {
        $options = $this->send(SetonoSyliusShipmondoExtension::HTTP_CLIENT, $host);

        self::assertNotSame(10.0, $options['timeout']);
        // Symfony's default, no limit at all
        self::assertEquals(0, $options['max_duration']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherHosts(): iterable
    {
        yield 'another host' => ['example.com'];
        yield "Shipmondo's website" => ['www.shipmondo.com'];
        yield 'a host that starts like the API' => ['app.shipmondo.com.example.com'];
    }

    /**
     * @test
     */
    public function the_shipmondo_client_sends_through_the_plugins_http_client(): void
    {
        $client = self::getContainer()->get(Client::class);
        Assert::isInstanceOf($client, Client::class);

        $client->paymentGateways()->getPage();

        $requests = $this->transport->requests();
        self::assertCount(1, $requests);
        self::assertStringStartsWith('https://app.shipmondo.com/api/public/v3/payment_gateways', $requests[0]['url']);
        self::assertSame(10.0, $requests[0]['options']['timeout']);
        self::assertSame(30.0, $requests[0]['options']['max_duration']);
    }

    /**
     * Sends a request to the host through the PSR-18 version of the plugin's HTTP client, and returns the options the
     * HTTP client sent it with
     *
     * @return array<string, mixed>
     */
    private function send(string $httpClient, string $host): array
    {
        $psr18 = self::getContainer()->get('psr18.' . $httpClient);
        Assert::isInstanceOf($psr18, HttpClientInterface::class);

        $psr18->sendRequest((new Psr17Factory())->createRequest('GET', sprintf('https://%s/api/public/v3/payment_gateways', $host)));

        $requests = $this->transport->requests();
        $request = end($requests);
        Assert::isArray($request);

        return $request['options'];
    }
}
