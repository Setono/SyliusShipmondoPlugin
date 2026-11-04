<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Playwright\Shop;

use Playwright\Locator\LocatorInterface;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;
use Tests\Setono\SyliusShipmondoPlugin\Playwright\BrowserTestCase;
use Webmozart\Assert\Assert;

/**
 * The shop's checkout with pickup point delivery: the shipping step loads pickup-point-manager.js, which asks the
 * plugin's pickup point endpoint (GetPickupPointsAction) for the pickup points of every pickup point shipping method
 * and lists them below the selected one. The endpoint searches them through the Shipmondo SDK, whose HTTP transport
 * answers with pickup points captured from the Shipmondo API (Support/shipmondo/pickup_points.json).
 */
abstract class ShopTestCase extends BrowserTestCase
{
    protected const CUSTOMER_EMAIL = 'shopper@example.com';

    protected const PICKUP_POINT_FIELD = 'sylius_checkout_select_shipping[shipments][0][shipmondoPickupPoint]';

    protected const ADDRESS = [
        'firstName' => 'Karen',
        'lastName' => 'Jensen',
        'street' => 'Vesterbro 1',
        'city' => 'Aalborg',
        'postcode' => '9000',
    ];

    /**
     * The pickup points the Shipmondo API answers with
     *
     * @return list<array{id: string, name: string, address: string, zipcode: string, city: string, country: string, opening_hours: list<string>}>
     */
    protected static function pickupPoints(): array
    {
        /** @var list<array{id: string, name: string, address: string, zipcode: string, city: string, country: string, opening_hours: list<string>}> $pickupPoints */
        $pickupPoints = self::shipmondoResponse('pickup_points');

        return $pickupPoints;
    }

    protected function respondWithPickupPoints(): void
    {
        $this->shipmondo->respond('GET', 'pickup_points', 200, self::pickupPoints());
    }

    /**
     * Puts the product in a new cart and completes the address step as a guest, ending on the shipping step
     *
     * @param array<string, string> $address
     */
    protected function checkoutToShippingStep(array $address = []): void
    {
        $address += self::ADDRESS;

        $page = $this->visit('/en_US/products/t-shirt');
        // the product page adds to the cart with an AJAX request and then goes to the cart itself
        $page->getByRole('button', ['name' => 'Add to cart'])->click();
        $this->waitUntil('() => location.pathname === "/en_US/cart/" && document.readyState === "complete"', 'The product was not added to the cart');

        $this->clickAndFollowRedirects($page->getByRole('button', ['name' => 'Checkout']));
        $this->assertOnStep('address');

        $page->getByLabel('Email')->fill(self::CUSTOMER_EMAIL);
        $page->locator('#sylius_checkout_address_billingAddress_firstName')->fill($address['firstName']);
        $page->locator('#sylius_checkout_address_billingAddress_lastName')->fill($address['lastName']);
        $page->locator('#sylius_checkout_address_billingAddress_street')->fill($address['street']);
        $page->locator('#sylius_checkout_address_billingAddress_countryCode')->selectOption(ShopFixtures::COUNTRY_CODE);
        $page->locator('#sylius_checkout_address_billingAddress_city')->fill($address['city']);
        $page->locator('#sylius_checkout_address_billingAddress_postcode')->fill($address['postcode']);
        $this->clickAndFollowRedirects($page->getByRole('button', ['name' => 'Next']));
        $this->assertOnStep('select-shipping');

        // the shipping step asks for the pickup points as soon as it has loaded; answer that before the test goes on
        $this->settle(0.5);
    }

    protected function continueToPaymentStep(): void
    {
        $this->clickAndFollowRedirects($this->getPage()->getByRole('button', ['name' => 'Next']));
        $this->assertOnStep('select-payment');
    }

    protected function backToShippingStep(): void
    {
        $this->clickAndFollowRedirects($this->getPage()->getByRole('link', ['name' => 'Change shipping method']));
        $this->assertOnStep('select-shipping');
    }

    protected function assertOnStep(string $step): void
    {
        self::assertSame(sprintf('http://localhost/en_US/checkout/%s', $step), $this->getPage()->url());
    }

    /**
     * Selects a shipping method the way a customer does: by clicking its name
     */
    protected function selectShippingMethod(string $name): void
    {
        $this->shippingMethods()->locator('label')->getByText($name, ['exact' => true])->click();
    }

    protected function shippingMethodRadio(string $code): LocatorInterface
    {
        return $this->shippingMethods()->locator(sprintf('input[type="radio"][name$="[method]"][value="%s"]', $code));
    }

    /**
     * The pickup point list the plugin inserts below the selected shipping method
     */
    protected function pickupPointList(): LocatorInterface
    {
        return $this->getPage()->locator('.pickup-points-container');
    }

    protected function pickupPointRadios(): LocatorInterface
    {
        return $this->pickupPointList()->locator('input[type="radio"]');
    }

    /**
     * The option (radio, address and opening hours) of the pickup point with the given name
     */
    protected function pickupPointOption(string $name): LocatorInterface
    {
        return $this->pickupPointList()->locator('.ui.grid')->filter(['hasText' => $name]);
    }

    /**
     * The shipping methods the browser asked the pickup point endpoint for
     *
     * @return list<string>
     */
    protected function requestedPickupPoints(): array
    {
        return array_map(
            static fn ($request): string => (string) $request->query->get('shippingMethod'),
            $this->handledRequests('/ajax/get-pickup-points'),
        );
    }

    /**
     * The pickup point stored on the shipment of the customer's latest order (a cart or a placed order)
     *
     * @return array{order_state: string, pickup_point: array<string, mixed>|null}
     */
    protected function pickupPointOfLatestOrder(): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT o.state, s.shipmondoPickupPoint FROM sylius_order o
             JOIN sylius_customer c ON c.id = o.customer_id
             JOIN sylius_shipment s ON s.order_id = o.id
             WHERE c.email = ?
             ORDER BY o.id DESC
             LIMIT 1',
            [self::CUSTOMER_EMAIL],
        );
        Assert::isArray($row, 'The customer has no order');
        Assert::string($row['state']);

        $pickupPoint = null;
        if (null !== $row['shipmondoPickupPoint']) {
            Assert::string($row['shipmondoPickupPoint']);
            $pickupPoint = json_decode($row['shipmondoPickupPoint'], true, 512, \JSON_THROW_ON_ERROR);
            Assert::isArray($pickupPoint);
        }

        return ['order_state' => $row['state'], 'pickup_point' => $pickupPoint];
    }

    private function shippingMethods(): LocatorInterface
    {
        return $this->getPage()->locator('#sylius-shipping-methods');
    }
}
