<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Playwright\Shop;

use function Playwright\Testing\expect;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;

/**
 * Pickup point delivery through the whole checkout: what the plugin asks Shipmondo for, and that the chosen pickup
 * point ends up on the order
 */
final class PickupPointCheckoutTest extends ShopTestCase
{
    /**
     * @test
     */
    public function pickup_points_are_searched_near_the_shipping_address_with_the_carrier_of_the_shipping_method(): void
    {
        $this->respondWithPickupPoints();

        $this->checkoutToShippingStep(['street' => 'Boulevarden 13', 'postcode' => '9000']);

        $searches = $this->shipmondo->requests('GET', 'pickup_points');
        self::assertCount(1, $searches);
        parse_str($searches[0]->getUri()->getQuery(), $query);
        self::assertSame([
            'carrier_code' => 'gls',
            'country_code' => ShopFixtures::COUNTRY_CODE,
            'zipcode' => '9000',
            'address' => 'Boulevarden 13',
        ], $query);
    }

    /**
     * @test
     */
    public function the_chosen_pickup_point_is_kept_through_the_checkout_and_saved_on_the_order(): void
    {
        $this->respondWithPickupPoints();
        [, $chosen] = self::pickupPoints();
        $this->checkoutToShippingStep();

        $this->selectShippingMethod(self::PICKUP_POINT_SHIPPING_METHOD_NAME);
        expect($this->pickupPointRadios())->toHaveCount(2);
        expect($this->pickupPointRadios()->first())->toBeChecked();
        $this->pickupPointOption($chosen['name'])->locator('input[type="radio"]')->check();
        $this->continueToPaymentStep();

        // back on the shipping step the chosen pickup point is listed first and preselected
        $this->backToShippingStep();
        expect($this->shippingMethodRadio(ShopFixtures::SHIPPING_METHOD_CODE))->toBeChecked();
        expect($this->pickupPointRadios())->toHaveCount(2);
        expect($this->pickupPointList()->locator('.ui.grid')->first())->toContainText($chosen['name']);
        expect($this->pickupPointRadios()->first())->toBeChecked();
        $this->continueToPaymentStep();

        $this->clickAndFollowRedirects($this->getPage()->getByRole('button', ['name' => 'Next']));
        $this->assertOnStep('complete');
        $this->clickAndFollowRedirects($this->getPage()->getByRole('button', ['name' => 'Place order']));
        self::assertSame('http://localhost/en_US/order/thank-you', $this->getPage()->url());

        $order = $this->pickupPointOfLatestOrder();
        self::assertSame('new', $order['order_state']);
        self::assertNotNull($order['pickup_point']);
        foreach (['id', 'name', 'address', 'zipcode', 'city', 'country'] as $key) {
            self::assertSame($chosen[$key], $order['pickup_point'][$key], sprintf('The pickup point %s', $key));
        }
    }

    /**
     * @test
     */
    public function the_pickup_point_is_forgotten_when_the_customer_switches_to_a_shipping_method_without_pickup_points(): void
    {
        $this->respondWithPickupPoints();
        $this->checkoutToShippingStep();

        $this->selectShippingMethod(self::PICKUP_POINT_SHIPPING_METHOD_NAME);
        expect($this->pickupPointRadios())->toHaveCount(2);
        $this->continueToPaymentStep();
        self::assertSame(self::pickupPoints()[0]['id'], $this->pickupPointOfLatestOrder()['pickup_point']['id'] ?? null);

        $this->backToShippingStep();
        // wait until the preselected pickup point method's pickup points are shown: switching before they load still
        // saves a pickup point (https://github.com/Setono/SyliusShipmondoPlugin/issues/27)
        expect($this->pickupPointRadios())->toHaveCount(2);
        $this->selectShippingMethod(self::REGULAR_SHIPPING_METHOD_NAME);
        expect($this->pickupPointList())->toHaveCount(0);
        $this->continueToPaymentStep();

        self::assertSame(['order_state' => 'cart', 'pickup_point' => null], $this->pickupPointOfLatestOrder());
    }
}
