<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Provider\ShipmondoSetupProvider;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\PaymentMethod;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\ShippingMethod;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;

final class ShipmondoSetupProviderTest extends TestCase
{
    private ScriptedHttpClient $httpClient;

    private ShipmondoSetupProvider $provider;

    protected function setUp(): void
    {
        $this->httpClient = new ScriptedHttpClient();
        $this->provider = new ShipmondoSetupProvider(ShipmondoApi::client($this->httpClient));
    }

    /**
     * An upload run maps every order with the same provider, and kernel.reset resets it between requests and messages
     *
     * @test
     */
    public function it_fetches_the_payment_gateways_once_until_it_is_reset(): void
    {
        $this->httpClient
            ->respond(200, [self::paymentGateway(11), self::paymentGateway(12)])
            ->respond(200, [self::paymentGateway(13)])
        ;

        self::assertSame([11, 12], self::ids($this->provider->paymentGateways()));
        self::assertSame([11, 12], self::ids($this->provider->paymentGateways()));
        self::assertSame(['GET payment_gateways'], $this->httpClient->requestLines());

        $this->provider->reset();

        self::assertSame([13], self::ids($this->provider->paymentGateways()));
        self::assertSame(['GET payment_gateways', 'GET payment_gateways'], $this->httpClient->requestLines());
    }

    /**
     * @test
     */
    public function it_fetches_the_shipment_templates_once_until_it_is_reset(): void
    {
        $this->httpClient
            ->respond(200, [self::shipmentTemplate(21), self::shipmentTemplate(22)])
            ->respond(200, [self::shipmentTemplate(23)])
        ;

        self::assertSame([21, 22], self::ids($this->provider->shipmentTemplates()));
        self::assertSame([21, 22], self::ids($this->provider->shipmentTemplates()));
        self::assertSame(['GET shipment_templates'], $this->httpClient->requestLines());

        $this->provider->reset();

        self::assertSame([23], self::ids($this->provider->shipmentTemplates()));
        self::assertSame(['GET shipment_templates', 'GET shipment_templates'], $this->httpClient->requestLines());
    }

    /**
     * @test
     */
    public function a_payment_method_mapped_to_a_payment_gateway_shipmondo_no_longer_has_has_a_stale_payment_gateway(): void
    {
        $this->httpClient->respond(200, [self::paymentGateway(11), self::paymentGateway(12)]);

        self::assertSame(13, $this->provider->stalePaymentGateway(self::paymentMethod(13)));
        self::assertNull($this->provider->stalePaymentGateway(self::paymentMethod(12)));
    }

    /**
     * @test
     */
    public function an_unmapped_payment_method_has_no_stale_payment_gateway_and_needs_no_call_to_shipmondo(): void
    {
        self::assertNull($this->provider->stalePaymentGateway(self::paymentMethod(null)));
        self::assertSame([], $this->httpClient->requests());
    }

    /**
     * @test
     */
    public function the_allowed_shipment_templates_shipmondo_no_longer_has_are_stale(): void
    {
        $this->httpClient->respond(200, [self::shipmentTemplate(21), self::shipmentTemplate(22)]);

        self::assertSame([99, 98], $this->provider->staleShipmentTemplates(self::shippingMethod([99, 21, 98])));
        self::assertSame([], $this->provider->staleShipmentTemplates(self::shippingMethod([22, 21])));
    }

    /**
     * @test
     */
    public function a_shipping_method_without_allowed_shipment_templates_has_none_stale_and_needs_no_call_to_shipmondo(): void
    {
        self::assertSame([], $this->provider->staleShipmentTemplates(self::shippingMethod([])));
        self::assertSame([], $this->httpClient->requests());
    }

    /**
     * @param list<int> $allowedShipmentTemplates
     */
    private static function shippingMethod(array $allowedShipmentTemplates): ShippingMethod
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setAllowedShipmentTemplates($allowedShipmentTemplates);

        return $shippingMethod;
    }

    private static function paymentMethod(?int $paymentGateway): PaymentMethod
    {
        $paymentMethod = new PaymentMethod();
        $paymentMethod->setShipmondoId($paymentGateway);

        return $paymentMethod;
    }

    /**
     * @return array<string, mixed>
     */
    private static function shipmentTemplate(int $id): array
    {
        return [
            'id' => $id,
            'name' => sprintf('Template %d', $id),
            'sender' => ['country_code' => 'DK'],
            'receiver' => ['country_code' => 'DK'],
            'parcels' => [['quantity' => 1, 'weight' => 1000]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentGateway(int $id): array
    {
        return ['id' => $id, 'name' => sprintf('Gateway %d', $id), 'provider' => 'quickpay', 'merchant_number' => '1234'];
    }

    /**
     * @param iterable<object{id: int}> $resources
     *
     * @return list<int>
     */
    private static function ids(iterable $resources): array
    {
        $ids = [];
        foreach ($resources as $resource) {
            $ids[] = $resource->id;
        }

        return $ids;
    }
}
