<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Playwright\Admin;

use Playwright\Locator\LocatorInterface;
use function Playwright\Testing\expect;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;
use Tests\Setono\SyliusShipmondoPlugin\Playwright\BrowserTestCase;

/**
 * The admin "Shipmondo" page (Configuration > Shipmondo) maps Sylius payment methods to Shipmondo payment gateways
 * and Sylius shipping methods to Shipmondo shipment templates, and registers the webhooks. Server side the page
 * talks to the stubbed Shipmondo API.
 */
final class ShipmondoPageTest extends BrowserTestCase
{
    private const API_ERROR = 'Could not fetch data from the Shipmondo API. Please check your Shipmondo credentials.';

    private const NO_PAYMENT_GATEWAYS = 'There are no configured payment gateways inside Shipmondo.';

    private const WEBHOOKS_NOT_REGISTERED = 'Either you have not registered webhooks yet or you need to re-register them';

    private const QUICKPAY_ID = 4921;

    private const HOME_DELIVERY_TEMPLATE_ID = 31411785;

    private const PICKUP_PARCEL_TEMPLATE_ID = 31411783;

    protected function setUp(): void
    {
        parent::setUp();

        // the baseline payment method is already mapped; this one starts unmapped
        $this->createPaymentMethod('cash_on_delivery', 'Cash on delivery');
        $this->logInAsAdministrator();
    }

    /**
     * @test
     */
    public function the_page_is_reachable_from_the_configuration_menu(): void
    {
        $this->respondWithGatewaysAndTemplates();
        $page = $this->visit('/admin/');

        $configurationMenu = $page->locator('.sylius-admin-menu > .item')->filter(['has' => $page->locator('.header', ['hasText' => 'Configuration'])]);
        $this->clickAndFollowRedirects($configurationMenu->getByRole('link', ['name' => 'Shipmondo']));

        expect($page)->toHaveURL('http://localhost/admin/shipmondo');
        expect($page->getByRole('heading', ['name' => 'Shipmondo']))->toBeVisible();
        expect($page->getByText('Manage your Shipmondo settings'))->toBeVisible();
    }

    /**
     * @test
     */
    public function a_payment_method_is_mapped_to_a_shipmondo_payment_gateway(): void
    {
        $this->respondWithGatewaysAndTemplates();
        $this->visit('/admin/shipmondo');
        $row = $this->mappingRow($this->paymentMethodsForm(), 'Cash on delivery');
        self::assertSame(['QuickPay', 'Stripe'], self::texts($row->locator('.ui.dropdown .menu .item')));

        self::pickDropdownOption($row->locator('.ui.dropdown'), 'QuickPay');
        $this->clickAndFollowRedirects($this->paymentMethodsForm()->getByRole('button', ['name' => 'Save changes']));

        // the page shows the mapping after saving, and it is stored
        expect($this->mappingRow($this->paymentMethodsForm(), 'Cash on delivery')->locator('select'))->toHaveValue((string) self::QUICKPAY_ID);
        expect($this->mappingRow($this->paymentMethodsForm(), 'Cash on delivery')->locator('.ui.dropdown > .text'))->toHaveExactText('QuickPay');
        self::assertSame([
            'bank_transfer' => ShopFixtures::PAYMENT_METHOD_SHIPMONDO_ID,
            'cash_on_delivery' => self::QUICKPAY_ID,
        ], $this->paymentMethodMappings());
    }

    /**
     * @test
     */
    public function shipment_templates_are_mapped_to_a_shipping_method(): void
    {
        $this->respondWithGatewaysAndTemplates();
        $page = $this->visit('/admin/shipmondo');
        $dropdown = $this->mappingRow($this->shippingMethodsForm(), self::REGULAR_SHIPPING_METHOD_NAME)->locator('.ui.dropdown');

        self::pickDropdownOption($dropdown, 'DK - Bring - Home Delivery Parcel - 0-1 kg');
        $page->keyboard()->press('Escape');
        self::pickDropdownOption($dropdown, 'DK - Bring - Pickup Parcel - 0-1 kg');
        $page->keyboard()->press('Escape');
        $this->clickAndFollowRedirects($this->shippingMethodsForm()->getByRole('button', ['name' => 'Save changes']));

        $this->visit('/admin/shipmondo');
        $labels = $this->mappingRow($this->shippingMethodsForm(), self::REGULAR_SHIPPING_METHOD_NAME)->locator('.ui.dropdown a.ui.label');
        expect($labels)->toHaveCount(2);
        self::assertSame(['DK - Bring - Home Delivery Parcel - 0-1 kg', 'DK - Bring - Pickup Parcel - 0-1 kg'], self::texts($labels));
        self::assertSame([self::HOME_DELIVERY_TEMPLATE_ID, self::PICKUP_PARCEL_TEMPLATE_ID], $this->allowedShipmentTemplates(self::REGULAR_SHIPPING_METHOD_CODE));
        self::assertSame([], $this->allowedShipmentTemplates(ShopFixtures::SHIPPING_METHOD_CODE));
    }

    /**
     * @test
     */
    public function the_webhooks_are_registered_with_shipmondo(): void
    {
        $this->respondWithGatewaysAndTemplates();
        $this->respondWithList('webhooks');
        // the plugin replaces the webhook it registered before (8500473) and leaves the other one (8500472) alone:
        // deleting that one would be a request this test did not stub
        $this->shipmondo->respond('DELETE', 'webhooks/8500473', 204, null);
        $this->shipmondo->respond('POST', 'webhooks', 201, self::shipmondoResponse('webhooks')[1]);
        $page = $this->visit('/admin/shipmondo');
        expect($page->getByText(self::WEBHOOKS_NOT_REGISTERED))->toBeVisible();

        $this->clickAndFollowRedirects($page->getByRole('link', ['name' => 'Register webhooks']));

        expect($page)->toHaveURL('http://localhost/admin/shipmondo');
        expect($this->flash('success'))->toContainText('Webhooks registered successfully');
        expect($page->getByText('Webhooks were registered on'))->toBeVisible();
        expect($page->getByText(self::WEBHOOKS_NOT_REGISTERED))->toBeHidden();

        $created = $this->shipmondo->requests('POST', 'webhooks');
        self::assertNotEmpty($created);
        foreach ($created as $request) {
            $webhook = ShipmondoApiStub::jsonBody($request);
            self::assertIsString($webhook['name']);
            self::assertStringStartsWith('Setono Sylius Shipmondo Plugin - [', $webhook['name']);
            self::assertSame('http://localhost/webhook/shipmondo', $webhook['endpoint']);
        }
    }

    /**
     * @test
     */
    public function the_page_explains_the_problem_when_shipmondo_rejects_the_credentials(): void
    {
        $this->shipmondo->respond('GET', 'payment_gateways', 401, ['error' => 'Invalid API credentials']);

        $page = $this->visit('/admin/shipmondo');

        expect($this->flash('error'))->toContainText(self::API_ERROR);
        expect($page->getByText(self::NO_PAYMENT_GATEWAYS))->toBeVisible();
        expect($page->locator('select[name^="payment_methods"]'))->toHaveCount(0);
        expect($page->locator('select[name^="shipping_methods"]'))->toHaveCount(0);
        // the webhooks can still be registered once the credentials are fixed
        expect($page->getByRole('link', ['name' => 'Register webhooks']))->toBeVisible();
    }

    /**
     * @test
     */
    public function the_page_tells_the_admin_to_create_a_payment_gateway_when_shipmondo_has_none(): void
    {
        $this->respondWithList('payment_gateways', []);
        $this->respondWithList('shipment_templates', []);

        $page = $this->visit('/admin/shipmondo');

        expect($this->flash('error'))->toHaveCount(0);
        expect($page->getByText(self::NO_PAYMENT_GATEWAYS))->toBeVisible();
        expect($page->getByRole('link', ['name' => 'Shipmondo', 'exact' => true])->last())->toHaveAttribute('href', 'https://app.shipmondo.com/main/app/#/setting/integration/payment-gateway');
        expect($page->locator('select[name^="payment_methods"]'))->toHaveCount(0);
    }

    /**
     * @test
     */
    public function the_page_tells_the_admin_to_create_a_shipment_template_when_shipmondo_has_none(): void
    {
        self::skipUntilFixed(19);

        $this->respondWithList('payment_gateways', []);
        $this->respondWithList('shipment_templates', []);

        $page = $this->visit('/admin/shipmondo');

        $segment = $page->locator('.ui.segment')->filter(['has' => $page->getByRole('heading', ['name' => 'Map shipping methods'])]);
        expect($segment)->not()->toContainText('setono_sylius_shipmondo.ui.');
        expect($segment)->toContainText('shipment template');
    }

    /**
     * @test
     */
    public function a_payment_method_mapping_can_be_removed(): void
    {
        // an empty choice is skipped when saving (and the dropdown offers no empty choice, so the native select is emptied)
        self::skipUntilFixed(18);

        $this->respondWithGatewaysAndTemplates();
        $this->fixtures->paymentMethod->setShipmondoId(self::QUICKPAY_ID);
        $this->entityManager()->flush();
        $this->visit('/admin/shipmondo');
        $select = $this->mappingRow($this->paymentMethodsForm(), 'Bank transfer')->locator('select');
        expect($select)->toHaveValue((string) self::QUICKPAY_ID);

        $select->selectOption('', ['force' => true]);
        $this->clickAndFollowRedirects($this->paymentMethodsForm()->getByRole('button', ['name' => 'Save changes']));

        self::assertNull($this->paymentMethodMappings()['bank_transfer']);
    }

    /**
     * @test
     */
    public function all_shipment_templates_can_be_removed_from_a_shipping_method(): void
    {
        // a multi-select without any selected option is not submitted, so the last template can never be removed
        self::skipUntilFixed(18);

        $this->respondWithGatewaysAndTemplates();
        $this->regularShippingMethod->setAllowedShipmentTemplates([self::HOME_DELIVERY_TEMPLATE_ID]);
        $this->entityManager()->flush();
        $this->visit('/admin/shipmondo');
        $row = $this->mappingRow($this->shippingMethodsForm(), self::REGULAR_SHIPPING_METHOD_NAME);
        expect($row->locator('.ui.dropdown a.ui.label'))->toHaveExactText('DK - Bring - Home Delivery Parcel - 0-1 kg');

        $row->locator('.ui.dropdown a.ui.label .delete.icon')->click();
        $this->clickAndFollowRedirects($this->shippingMethodsForm()->getByRole('button', ['name' => 'Save changes']));

        self::assertSame([], $this->allowedShipmentTemplates(self::REGULAR_SHIPPING_METHOD_CODE));
    }

    /**
     * @test
     */
    public function registering_the_webhooks_explains_the_problem_when_shipmondo_rejects_the_credentials(): void
    {
        // registering the webhooks while the Shipmondo API fails ends in an HTTP 500 error page
        self::skipUntilFixed(24);

        $this->shipmondo->respond('GET', 'payment_gateways', 401, ['error' => 'Invalid API credentials']);
        $this->shipmondo->respond('GET', 'webhooks', 401, ['error' => 'Invalid API credentials']);
        $page = $this->visit('/admin/shipmondo');

        $this->clickAndFollowRedirects($page->getByRole('link', ['name' => 'Register webhooks']));

        self::assertLessThan(500, $this->getLastResponse()?->getStatusCode() ?? 500);
        expect($page)->toHaveURL('http://localhost/admin/shipmondo');
        expect($this->flash('error'))->toBeVisible();
    }

    /**
     * @return list<string>
     */
    private static function texts(LocatorInterface $locator): array
    {
        $texts = $locator->evaluateAll('(elements) => elements.map((element) => element.textContent.trim())');
        self::assertIsArray($texts);

        return array_values(array_map('strval', $texts));
    }

    private function respondWithGatewaysAndTemplates(): void
    {
        $this->respondWithList('payment_gateways');
        $this->respondWithList('shipment_templates');
    }

    private function paymentMethodsForm(): LocatorInterface
    {
        return $this->getPage()->locator('form:has(select[name^="payment_methods"])');
    }

    private function shippingMethodsForm(): LocatorInterface
    {
        return $this->getPage()->locator('form:has(select[name^="shipping_methods"])');
    }

    private function mappingRow(LocatorInterface $form, string $name): LocatorInterface
    {
        return $form->locator(sprintf('tbody tr:has(> td:text-is("%s"))', $name));
    }

    /**
     * @return array<string, int|null> the Shipmondo id of each payment method, by code
     */
    private function paymentMethodMappings(): array
    {
        $mappings = [];
        foreach ($this->connection()->fetchAllAssociative('SELECT code, shipmondoId FROM sylius_payment_method ORDER BY code') as $row) {
            self::assertIsString($row['code']);
            $mappings[$row['code']] = null === $row['shipmondoId'] ? null : (int) $row['shipmondoId'];
        }

        return $mappings;
    }

    /**
     * @return list<int>
     */
    private function allowedShipmentTemplates(string $shippingMethodCode): array
    {
        $json = $this->connection()->fetchOne('SELECT allowedShipmentTemplates FROM sylius_shipping_method WHERE code = ?', [$shippingMethodCode]);
        if (null === $json || false === $json) {
            return [];
        }

        self::assertIsString($json);
        $templates = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($templates);

        return array_values(array_map('intval', $templates));
    }
}
