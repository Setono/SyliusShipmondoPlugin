<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Playwright\Shop;

use function Playwright\Testing\expect;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;

/**
 * What pickup-point-manager.js does on the shipping step: which shipping methods it asks pickup points for, how it
 * lists them, and how it reacts when the customer switches shipping methods or the pickup points cannot be loaded
 */
final class PickupPointSelectionTest extends ShopTestCase
{
    /**
     * @test
     */
    public function pickup_points_are_only_requested_for_pickup_point_shipping_methods(): void
    {
        $this->respondWithPickupPoints();

        $this->checkoutToShippingStep();

        // they are requested when the step loads, before the customer picks a shipping method
        self::assertSame([ShopFixtures::SHIPPING_METHOD_CODE], $this->requestedPickupPoints());
        expect($this->shippingMethodRadio(self::REGULAR_SHIPPING_METHOD_CODE))->toBeChecked();
        expect($this->pickupPointList())->toHaveCount(0);
    }

    /**
     * @test
     */
    public function selecting_a_pickup_point_shipping_method_lists_its_pickup_points_to_choose_from(): void
    {
        $this->respondWithPickupPoints();
        $this->checkoutToShippingStep();
        $this->recordPickupPointManagerEvents();

        $this->selectShippingMethod(self::PICKUP_POINT_SHIPPING_METHOD_NAME);

        expect($this->pickupPointList())->toBeVisible();
        // the list is inserted right below the selected shipping method
        self::assertTrue($this->shippingMethodRadio(ShopFixtures::SHIPPING_METHOD_CODE)->evaluate(
            '(radio) => radio.closest(".item").nextElementSibling.classList.contains("pickup-points-container")',
        ));

        foreach (self::pickupPoints() as $pickupPoint) {
            $option = $this->pickupPointOption($pickupPoint['name']);
            expect($option)->toContainText($pickupPoint['address']);
            expect($option)->toContainText(sprintf('%s %s', $pickupPoint['zipcode'], $pickupPoint['city']));
            foreach ($pickupPoint['opening_hours'] as $openingHours) {
                expect($option)->toContainText($openingHours);
            }
        }

        // the radios are named so they are submitted with the shipment, and the first pickup point is preselected
        expect($this->pickupPointRadios())->toHaveCount(2);
        foreach ($this->pickupPointRadios()->all() as $radio) {
            expect($radio)->toHaveAttribute('name', self::PICKUP_POINT_FIELD);
        }
        expect($this->pickupPointRadios()->first())->toBeChecked();

        $this->pickupPointOption(self::pickupPoints()[1]['name'])->locator('input[type="radio"]')->check();
        expect($this->pickupPointRadios()->nth(1))->toBeChecked();
        expect($this->pickupPointRadios()->first())->not()->toBeChecked();

        self::assertSame([ShopFixtures::SHIPPING_METHOD_CODE], $this->pickupPointManagerEvents('inserted_html'));
    }

    /**
     * @test
     */
    public function the_pickup_points_are_removed_for_another_shipping_method_and_come_back_without_a_new_request(): void
    {
        $this->respondWithPickupPoints();
        $this->checkoutToShippingStep();

        $this->selectShippingMethod(self::PICKUP_POINT_SHIPPING_METHOD_NAME);
        expect($this->pickupPointList())->toBeVisible();

        $this->selectShippingMethod(self::REGULAR_SHIPPING_METHOD_NAME);
        expect($this->pickupPointList())->toHaveCount(0);

        $this->selectShippingMethod(self::PICKUP_POINT_SHIPPING_METHOD_NAME);
        expect($this->pickupPointList())->toBeVisible();
        expect($this->pickupPointRadios())->toHaveCount(2);

        self::assertSame([ShopFixtures::SHIPPING_METHOD_CODE], $this->requestedPickupPoints());
    }

    /**
     * @test
     */
    public function when_the_pickup_points_cannot_be_loaded_none_are_shown_and_the_checkout_can_continue(): void
    {
        // the endpoint fails with HTTP 500 when the Shipmondo API does
        $this->shipmondo->respond('GET', 'pickup_points', 500, ['error' => 'Internal server error']);
        $this->recordConsoleErrors();
        $this->checkoutToShippingStep();

        $this->selectShippingMethod(self::PICKUP_POINT_SHIPPING_METHOD_NAME);

        $this->waitUntil(
            '() => window.consoleErrors.some((error) => error.startsWith("Failed to load pickup point data"))',
            'The pickup point manager did not report the failed request',
        );
        expect($this->pickupPointList())->toHaveCount(0);

        $this->continueToPaymentStep();
    }

    /**
     * @test
     */
    public function when_the_pickup_points_cannot_be_loaded_the_manager_stops_waiting_for_them(): void
    {
        // after a failed request the manager dispatches a loading event and checks for the pickup points every
        // 100 ms for as long as the page is open, and the customer gets no feedback
        self::skipUntilFixed(23);

        $this->shipmondo->respond('GET', 'pickup_points', 500, ['error' => 'Internal server error']);
        $this->checkoutToShippingStep();
        $this->recordPickupPointManagerEvents();

        $this->selectShippingMethod(self::PICKUP_POINT_SHIPPING_METHOD_NAME);
        $this->settle(3);

        self::assertLessThan(5, count($this->pickupPointManagerEvents('loading')));
    }

    /**
     * @test
     */
    public function no_pickup_points_are_requested_when_no_shipping_method_has_pickup_point_delivery(): void
    {
        // with no pickup point shipping method the page gets `allowedShippingMethods: []`, which the manager treats
        // as "every shipping method": it requests pickup points for all of them (each request fails with HTTP 400)
        self::skipUntilFixed(22);

        $this->fixtures->shippingMethod->setPickupPointDelivery(false);
        $this->entityManager()->flush();

        $this->checkoutToShippingStep();
        $this->settle();

        self::assertSame([], $this->requestedPickupPoints());
    }

    /**
     * @test
     */
    public function switching_to_another_shipping_method_before_the_pickup_points_have_loaded_does_not_show_them(): void
    {
        // the manager inserts the pickup points once they arrive, also when the customer has selected another
        // shipping method in the meantime; the preselected pickup point is then saved on that method's shipment
        self::skipUntilFixed(27);

        $this->respondWithPickupPoints();
        // the page gets the pickup points only when the test says so
        $this->getPage()->addInitScript(<<<'JS'
            (() => {
                const fetch = window.fetch;
                let release;
                const released = new Promise((resolve) => { release = resolve; });
                window.releasePickupPoints = () => release();
                window.fetch = async (...args) => {
                    const response = await fetch(...args);
                    if (String(args[0]).includes('/ajax/get-pickup-points')) {
                        await released;
                    }

                    return response;
                };
            })();
            JS);
        $this->checkoutToShippingStep();

        $this->selectShippingMethod(self::PICKUP_POINT_SHIPPING_METHOD_NAME);
        $this->selectShippingMethod(self::REGULAR_SHIPPING_METHOD_NAME);
        $this->getPage()->evaluate('() => window.releasePickupPoints()');
        // give the manager, which checks for the pickup points every 100 ms, time to (wrongly) insert them
        $this->settle();

        expect($this->shippingMethodRadio(self::REGULAR_SHIPPING_METHOD_CODE))->toBeChecked();
        expect($this->pickupPointList())->toHaveCount(0);
    }

    /**
     * Records the events pickup-point-manager.js dispatches on the page from now on
     */
    private function recordPickupPointManagerEvents(): void
    {
        $this->getPage()->evaluate(<<<'JS'
            () => {
                window.pickupPointManagerEvents = { loading: [], inserted_html: [] };
                for (const name of Object.keys(window.pickupPointManagerEvents)) {
                    document.addEventListener(`pickup_point_manager:${name}`, (event) => window.pickupPointManagerEvents[name].push(event.detail.shippingMethod));
                }
            }
            JS);
    }

    /**
     * @return list<string> the shipping method of each recorded event
     */
    private function pickupPointManagerEvents(string $name): array
    {
        $events = $this->getPage()->evaluate('(name) => window.pickupPointManagerEvents[name]', $name);
        self::assertIsArray($events);

        return array_values(array_map('strval', $events));
    }

    /**
     * Records what the pages log as errors, in window.consoleErrors
     */
    private function recordConsoleErrors(): void
    {
        $this->getPage()->addInitScript(<<<'JS'
            (() => {
                window.consoleErrors = [];
                const error = console.error;
                console.error = (...args) => {
                    window.consoleErrors.push(args.join(' '));
                    error(...args);
                };
            })();
            JS);
    }
}
