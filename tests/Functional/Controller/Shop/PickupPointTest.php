<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Controller\Shop;

use Psr\Http\Message\RequestInterface;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;

/**
 * A customer choosing a pickup point in the checkout: the shop's pickup point endpoint and the shipping step
 */
final class PickupPointTest extends FunctionalTestCase
{
    private const ENDPOINT = '/en_US/ajax/get-pickup-points';

    /**
     * @test
     */
    public function it_returns_the_pickup_points_near_the_shipping_address_of_the_cart(): void
    {
        $this->cartOfLoggedInCustomer();
        $this->respondWithPickupPoints();

        $this->browser->request('GET', self::ENDPOINT, ['shippingMethod' => ShopFixtures::SHIPPING_METHOD_CODE]);

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertSame(['96285', '96286'], array_column($json['pickupPoints'], 'id'));
        self::assertStringContainsString('Pakkeshop Netto Vestergade', $json['html']);
        self::assertStringContainsString('Pakkeshop Føtex Frederiks Allé', $json['html']);

        $requests = $this->shipmondo->requests('GET', 'pickup_points');
        self::assertCount(1, $requests);
        self::assertSame([
            'carrier_code' => 'gls',
            'country_code' => ShopFixtures::COUNTRY_CODE,
            'zipcode' => '8000',
            'address' => 'Vestergade 1',
        ], self::query($requests[0]));
    }

    /**
     * @test
     */
    public function it_lists_the_pickup_point_the_customer_already_chose_first(): void
    {
        $cart = $this->cartOfLoggedInCustomer();
        $shipment = $cart->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);
        $shipment->setShipmondoPickupPoint(['id' => '96286', 'name' => 'Pakkeshop Føtex Frederiks Allé']);
        $this->entityManager()->flush();
        $this->respondWithPickupPoints();

        $this->browser->request('GET', self::ENDPOINT, ['shippingMethod' => ShopFixtures::SHIPPING_METHOD_CODE]);

        self::assertResponseIsSuccessful();
        self::assertSame(['96286', '96285'], array_column($this->json()['pickupPoints'], 'id'));
    }

    /**
     * @test
     */
    public function it_rejects_a_request_without_a_shipping_method(): void
    {
        $this->cartOfLoggedInCustomer();

        $this->browser->request('GET', self::ENDPOINT);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * @test
     */
    public function it_rejects_a_shipping_method_that_does_not_exist(): void
    {
        $this->cartOfLoggedInCustomer();

        $this->browser->request('GET', self::ENDPOINT, ['shippingMethod' => 'unknown']);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * @test
     */
    public function it_rejects_a_visitor_without_a_shipping_address(): void
    {
        $this->fixtures->createShop();

        $this->browser->request('GET', self::ENDPOINT, ['shippingMethod' => ShopFixtures::SHIPPING_METHOD_CODE]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * The shipping step of the checkout loads the pickup point picker and stores the chosen pickup point on the
     * shipment, from where it is uploaded to Shipmondo as the sales order's service point.
     *
     * @test
     */
    public function it_stores_the_chosen_pickup_point_on_the_shipment_in_the_checkout(): void
    {
        $cart = $this->cartOfLoggedInCustomer();

        $crawler = $this->browser->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        // the pickup point picker is loaded for the shipping methods with pickup point delivery
        self::assertStringContainsString('PickupPointManager', (string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString(
            sprintf('allowedShippingMethods: ["%s"]', ShopFixtures::SHIPPING_METHOD_CODE),
            (string) $this->browser->getResponse()->getContent(),
        );

        $pickupPoint = ['id' => '96285', 'name' => 'Pakkeshop Netto Vestergade', 'address' => 'Vestergade 20', 'zipcode' => '8000', 'city' => 'Aarhus C', 'country' => 'DK'];

        $form = $crawler->filter('form[name="sylius_checkout_select_shipping"]')->form();
        self::choice($form, 'sylius_checkout_select_shipping[shipments][0][method]')->setValue(ShopFixtures::SHIPPING_METHOD_CODE);

        // pickup-point-manager.js injects the pickup points as radio buttons named after the method field
        // (…[method] → …[shipmondoPickupPoint]), each carrying the pickup point as JSON
        $values = $form->getPhpValues();
        self::assertIsArray($values['sylius_checkout_select_shipping']);
        self::assertIsArray($values['sylius_checkout_select_shipping']['shipments']);
        self::assertIsArray($values['sylius_checkout_select_shipping']['shipments'][0]);
        $values['sylius_checkout_select_shipping']['shipments'][0]['shipmondoPickupPoint'] = json_encode($pickupPoint, \JSON_THROW_ON_ERROR);
        $this->browser->request($form->getMethod(), $form->getUri(), $values);

        self::assertResponseRedirects('/en_US/checkout/select-payment');

        $stored = $this->observer()->fetchOne('SELECT shipmondoPickupPoint FROM sylius_shipment WHERE order_id = ?', [self::id($cart)]);
        self::assertIsString($stored);
        self::assertSame($pickupPoint, json_decode($stored, true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * @test
     */
    public function it_rejects_a_pickup_point_that_is_not_valid_json_without_failing(): void
    {
        self::skipUntilFixed(20);

        $this->cartOfLoggedInCustomer();

        $crawler = $this->browser->request('GET', '/en_US/checkout/select-shipping');
        $form = $crawler->filter('form[name="sylius_checkout_select_shipping"]')->form();
        self::choice($form, 'sylius_checkout_select_shipping[shipments][0][method]')->setValue(ShopFixtures::SHIPPING_METHOD_CODE);

        $values = $form->getPhpValues();
        self::assertIsArray($values['sylius_checkout_select_shipping']);
        self::assertIsArray($values['sylius_checkout_select_shipping']['shipments']);
        self::assertIsArray($values['sylius_checkout_select_shipping']['shipments'][0]);
        $values['sylius_checkout_select_shipping']['shipments'][0]['shipmondoPickupPoint'] = 'not json';
        $this->browser->request($form->getMethod(), $form->getUri(), $values);

        $status = $this->browser->getResponse()->getStatusCode();
        self::assertLessThan(500, $status, 'A tampered pickup point must be a form error, not a server error');
        self::assertNotSame(302, $status, 'A tampered pickup point must not be accepted');
    }

    private function cartOfLoggedInCustomer(): OrderInterface
    {
        $this->fixtures->createShop();
        $customer = $this->fixtures->createCustomer();
        $this->loginAs($this->fixtures->createShopUser($customer), 'shop');

        return $this->fixtures->createCart($customer);
    }

    private function respondWithPickupPoints(): void
    {
        $pickupPoint = static fn (string $id, string $name, string $address): array => [
            'id' => $id,
            'number' => $id,
            'company_name' => 'GLS',
            'name' => $name,
            'address' => $address,
            'address2' => null,
            'zipcode' => '8000',
            'city' => 'Aarhus C',
            'country' => 'DK',
            'opening_hours' => ['Man-Fre: 08:00-21:00'],
        ];

        $this->shipmondo->respond('GET', 'pickup_points', 200, [
            $pickupPoint('96285', 'Pakkeshop Netto Vestergade', 'Vestergade 20'),
            $pickupPoint('96286', 'Pakkeshop Føtex Frederiks Allé', 'Frederiks Allé 22'),
        ]);
    }

    /**
     * @return array{pickupPoints: list<array<string, mixed>>, html: string}
     */
    private function json(): array
    {
        /** @var array{pickupPoints: list<array<string, mixed>>, html: string} $json */
        $json = json_decode((string) $this->browser->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $json;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }
}
