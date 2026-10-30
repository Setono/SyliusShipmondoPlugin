<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Playwright\Admin;

use Playwright\Locator\LocatorInterface;
use function Playwright\Testing\expect;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;
use Tests\Setono\SyliusShipmondoPlugin\Playwright\BrowserTestCase;

/**
 * The plugin adds "Pickup point delivery" and a carrier select to the shipping method form (the test app renders
 * them as the README describes) and injects carrier.js, which only shows the carrier select while pickup point
 * delivery is checked. The shipping method grid gets a "Pickup point delivery" column.
 */
final class ShippingMethodFormTest extends BrowserTestCase
{
    private const CARRIER_REQUIRED = 'When the pickup point delivery is set, you have to select a carrier.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->logInAsAdministrator();
    }

    /**
     * @test
     */
    public function the_carrier_select_is_only_shown_while_pickup_point_delivery_is_checked(): void
    {
        $this->visit('/admin/shipping-methods/new');

        expect($this->pickupPointDeliveryCheckbox())->not()->toBeChecked();
        expect($this->carrierSelect())->toBeHidden();

        $this->pickupPointDeliveryToggle()->click();
        expect($this->pickupPointDeliveryCheckbox())->toBeChecked();
        expect($this->carrierSelect())->toBeVisible();
        expect($this->carrierSelect()->locator('option')->first())->toHaveExactText('Select carrier...');
        expect($this->carrierSelect()->locator('option[value="gls"]'))->toHaveExactText('GLS');
        expect($this->carrierSelect()->locator('option[value="post_nord"]'))->toHaveExactText('PostNord');

        $this->pickupPointDeliveryToggle()->click();
        expect($this->pickupPointDeliveryCheckbox())->not()->toBeChecked();
        expect($this->carrierSelect())->toBeHidden();
    }

    /**
     * @test
     */
    public function a_pickup_point_shipping_method_cannot_be_saved_without_a_carrier(): void
    {
        $this->visit('/admin/shipping-methods/new');
        $this->fillNewShippingMethod('pickup_without_carrier');
        $this->pickupPointDeliveryToggle()->click();

        $this->clickAndFollowRedirects($this->getPage()->getByRole('button', ['name' => 'Create']));

        expect($this->getPage()->getByText(self::CARRIER_REQUIRED))->toBeVisible();
        // the form is shown again with pickup point delivery checked, so the carrier select is shown right away
        expect($this->pickupPointDeliveryCheckbox())->toBeChecked();
        expect($this->carrierSelect())->toBeVisible();
    }

    /**
     * @test
     */
    public function a_pickup_point_shipping_method_is_saved_with_its_carrier_and_listed_with_it_in_the_grid(): void
    {
        $this->visit('/admin/shipping-methods/new');
        $this->fillNewShippingMethod('postnord_pickup');
        $this->pickupPointDeliveryToggle()->click();
        $this->carrierSelect()->selectOption(['label' => 'PostNord']);

        $this->clickAndFollowRedirects($this->getPage()->getByRole('button', ['name' => 'Create']));

        expect($this->flash('success'))->toContainText('Shipping method has been successfully created.');
        expect($this->pickupPointDeliveryCheckbox())->toBeChecked();
        expect($this->carrierSelect())->toBeVisible();
        expect($this->carrierSelect())->toHaveValue('post_nord');

        $this->visit('/admin/shipping-methods/');
        expect($this->gridRow('postnord_pickup')->getByText('Yes (post_nord)'))->toBeVisible();
    }

    /**
     * @test
     */
    public function the_edit_form_shows_the_carrier_of_a_pickup_point_shipping_method_right_away(): void
    {
        $this->visit('/admin/shipping-methods/');
        $this->clickAndFollowRedirects($this->gridRow(ShopFixtures::SHIPPING_METHOD_CODE)->getByRole('link', ['name' => 'Edit']));

        expect($this->pickupPointDeliveryCheckbox())->toBeChecked();
        expect($this->carrierSelect())->toBeVisible();
        expect($this->carrierSelect())->toHaveValue('gls');

        $this->pickupPointDeliveryToggle()->click();
        expect($this->carrierSelect())->toBeHidden();
    }

    /**
     * @test
     */
    public function the_shipping_method_grid_shows_whether_a_method_delivers_to_pickup_points(): void
    {
        $this->visit('/admin/shipping-methods/');

        expect($this->getPage()->getByRole('columnheader', ['name' => 'Pickup point delivery']))->toBeVisible();
        expect($this->gridRow(ShopFixtures::SHIPPING_METHOD_CODE)->getByText('Yes (gls)'))->toBeVisible();
        expect($this->gridRow(self::REGULAR_SHIPPING_METHOD_CODE)->getByText('No', ['exact' => true]))->toBeVisible();
    }

    private function carrierSelect(): LocatorInterface
    {
        return $this->getPage()->locator('#sylius_shipping_method_carrierCode');
    }

    private function pickupPointDeliveryCheckbox(): LocatorInterface
    {
        return $this->getPage()->locator('#sylius_shipping_method_pickupPointDelivery');
    }

    private function pickupPointDeliveryToggle(): LocatorInterface
    {
        return $this->getPage()->locator('label[for="sylius_shipping_method_pickupPointDelivery"]');
    }

    private function gridRow(string $code): LocatorInterface
    {
        return $this->getPage()->locator(sprintf('table tbody tr:has(> td:text-is("%s"))', $code));
    }

    /**
     * Fills everything a new shipping method needs
     */
    private function fillNewShippingMethod(string $code): void
    {
        $page = $this->getPage();
        $page->locator('#sylius_shipping_method_code')->fill($code);
        $page->locator('#sylius_shipping_method_zone')->selectOption(['label' => 'Denmark']);
        $page->locator('label[for="sylius_shipping_method_channels_0"]')->click();
        $page->locator(sprintf('#sylius_shipping_method_configuration_%s_amount', ShopFixtures::CHANNEL_CODE))->fill('4.95');
        $page->locator(sprintf('#sylius_shipping_method_translations_%s_name', ShopFixtures::LOCALE_CODE))->fill(sprintf('Pickup point %s', $code));
    }
}
