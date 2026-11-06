<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Controller\Shop;

use Psr\Http\Message\RequestInterface;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;
use Tests\Setono\SyliusShipmondoPlugin\Support\PickupPoints;

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
        $shipment->setShipmondoPickupPoint(PickupPoints::create('96286', 'Pakkeshop Føtex Frederiks Allé'));
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
    public function it_rejects_a_shipping_method_without_pickup_point_delivery(): void
    {
        $this->fixtures->createRegularShippingMethod()->setCarrierCode('ups');
        $this->entityManager()->flush();
        $this->cartOfLoggedInCustomer();

        $this->browser->request('GET', self::ENDPOINT, ['shippingMethod' => ShopFixtures::REGULAR_SHIPPING_METHOD_CODE]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * @test
     */
    public function it_rejects_a_visitor_without_a_shipping_address(): void
    {
        $this->browser->request('GET', self::ENDPOINT, ['shippingMethod' => ShopFixtures::SHIPPING_METHOD_CODE]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * The pickup point picker shows that the pickup points couldn't be loaded on any answer that isn't a success
     *
     * @test
     *
     * @dataProvider failedSearches
     */
    public function it_answers_bad_gateway_when_shipmondo_cannot_search_for_pickup_points(?int $statusCode): void
    {
        $this->cartOfLoggedInCustomer();
        if (null === $statusCode) {
            $this->shipmondo->failToConnect('GET', 'pickup_points');
        } else {
            $this->shipmondo->respond('GET', 'pickup_points', $statusCode, ['error' => 'Something went wrong']);
        }

        $this->browser->request('GET', self::ENDPOINT, ['shippingMethod' => ShopFixtures::SHIPPING_METHOD_CODE]);

        self::assertResponseStatusCodeSame(502);
        // the failure is logged, and the shop's visitors aren't told what went wrong
        self::assertSame('{}', $this->browser->getResponse()->getContent());
    }

    /**
     * @test
     */
    public function it_gives_the_pickup_point_picker_what_to_show_when_the_pickup_points_cannot_be_loaded(): void
    {
        $this->cartOfLoggedInCustomer();

        $this->browser->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        $script = $this->browser->getCrawler()->filter('script[type="module"]')->reduce(
            static fn (Crawler $script): bool => str_contains($script->text(), 'PickupPointManager'),
        );
        self::assertCount(1, $script);
        self::assertStringContainsString('loadFailedHtml: "', $script->text());
        self::assertStringContainsString('The pickup points could not be loaded. Try again, or choose another shipping method.', $script->text());
        // the button the picker loads the pickup points again with
        self::assertStringContainsString('data-pickup-point-manager-load-again', $script->text());
        self::assertStringContainsString('Try again', $script->text());
    }

    /**
     * The picker treats an empty list of pickup point shipping methods as all of them, so it would request pickup points
     * for every shipping method
     *
     * @test
     */
    public function the_shipping_step_has_no_pickup_point_picker_when_no_shipping_method_has_pickup_point_delivery(): void
    {
        $this->fixtures->shippingMethod->setPickupPointDelivery(false);
        $this->entityManager()->flush();
        $this->cartOfLoggedInCustomer();

        $this->browser->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('PickupPointManager', (string) $this->browser->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{?int}>
     */
    public static function failedSearches(): iterable
    {
        yield 'server error' => [500];
        yield 'network problem' => [null];
        yield 'credentials failure' => [401];
        yield 'rejected' => [400];
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

        $this->browser->request('GET', '/en_US/checkout/select-shipping');

        self::assertResponseIsSuccessful();
        // the pickup point picker is loaded for the shipping methods with pickup point delivery
        self::assertStringContainsString('PickupPointManager', (string) $this->browser->getResponse()->getContent());
        self::assertStringContainsString(
            sprintf('allowedShippingMethods: ["%s"]', ShopFixtures::SHIPPING_METHOD_CODE),
            (string) $this->browser->getResponse()->getContent(),
        );

        $this->submitShippingStep(ShopFixtures::SHIPPING_METHOD_CODE, PickupPoints::JSON);

        self::assertResponseRedirects('/en_US/checkout/select-payment');
        self::assertEquals(json_decode(PickupPoints::JSON, true), $this->storedPickupPoint($cart));
    }

    /**
     * Pickup points that arrive after the customer selected another shipping method, or a tampered request
     *
     * @test
     */
    public function it_drops_a_pickup_point_chosen_for_a_shipping_method_without_pickup_point_delivery(): void
    {
        $this->fixtures->createRegularShippingMethod();
        $cart = $this->cartOfLoggedInCustomer();

        $this->submitShippingStep(ShopFixtures::REGULAR_SHIPPING_METHOD_CODE, PickupPoints::JSON);

        self::assertResponseRedirects('/en_US/checkout/select-payment');
        self::assertNull($this->storedPickupPoint($cart));
    }

    /**
     * E.g. when the pickup point picker couldn't load the pickup points
     *
     * @test
     */
    public function it_asks_for_a_pickup_point_when_the_shipping_method_has_pickup_point_delivery(): void
    {
        $cart = $this->cartOfLoggedInCustomer();

        $this->submitShippingStep(ShopFixtures::SHIPPING_METHOD_CODE, null);

        $this->assertShippingStepShowsError('Please choose a pickup point, or another shipping method.');
        self::assertNull($this->storedPickupPoint($cart));
    }

    /**
     * A tampered request or a broken pickup point picker
     *
     * @test
     *
     * @dataProvider unreadablePickupPoints
     */
    public function it_rejects_a_pickup_point_it_cannot_read_without_failing(string $pickupPoint): void
    {
        $cart = $this->cartOfLoggedInCustomer();

        $this->submitShippingStep(ShopFixtures::SHIPPING_METHOD_CODE, $pickupPoint);

        $this->assertShippingStepShowsError('The chosen pickup point could not be read. Please choose it again.');
        self::assertNull($this->storedPickupPoint($cart));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePickupPoints(): iterable
    {
        yield 'not JSON (#20)' => ['not json'];
        yield 'JSON of the wrong shape (#37)' => ['{"id":"96285"}'];
    }

    private function cartOfLoggedInCustomer(): OrderInterface
    {
        $customer = $this->fixtures->createCustomer();
        $this->loginAs($this->fixtures->createShopUser($customer), 'shop');

        return $this->fixtures->createCart($customer);
    }

    /**
     * Submits the real shipping step form with the given shipping method and, as pickup-point-manager.js does, the
     * given pickup point in the field named after the method field (…[method] → …[shipmondoPickupPoint])
     */
    private function submitShippingStep(string $shippingMethod, ?string $pickupPoint): void
    {
        $crawler = $this->browser->request('GET', '/en_US/checkout/select-shipping');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="sylius_checkout_select_shipping"]')->form();
        self::choice($form, 'sylius_checkout_select_shipping[shipments][0][method]')->setValue($shippingMethod);

        $values = $form->getPhpValues();
        self::assertIsArray($values['sylius_checkout_select_shipping']);
        self::assertIsArray($values['sylius_checkout_select_shipping']['shipments']);
        self::assertIsArray($values['sylius_checkout_select_shipping']['shipments'][0]);
        if (null !== $pickupPoint) {
            $values['sylius_checkout_select_shipping']['shipments'][0]['shipmondoPickupPoint'] = $pickupPoint;
        }

        $this->browser->request($form->getMethod(), $form->getUri(), $values);
    }

    private function assertShippingStepShowsError(string $error): void
    {
        $response = $this->browser->getResponse();
        self::assertLessThan(500, $response->getStatusCode(), 'The shipping step must show an error, not fail');
        self::assertFalse($response->isRedirection(), 'The shipping step must not be accepted');

        // the errors Sylius renders at the top of the shipping step (form_errors(form))
        $errors = $this->browser->getCrawler()->filter('form[name="sylius_checkout_select_shipping"] > .sylius-validation-error');
        self::assertStringContainsString($error, $errors->text(''), 'The error must be shown at the top of the shipping step');
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function storedPickupPoint(OrderInterface $cart): ?array
    {
        $stored = $this->database()->fetchOne('SELECT shipmondoPickupPoint FROM sylius_shipment WHERE order_id = ?', [self::id($cart)]);
        if (null === $stored) {
            return null;
        }

        self::assertIsString($stored);
        $stored = json_decode($stored, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($stored);

        return $stored;
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
