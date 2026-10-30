<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Controller\Admin;

use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;

/**
 * The admin "Shipmondo" page: mapping payment and shipping methods to Shipmondo and registering webhooks
 */
final class ShipmondoControllerTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_requires_an_administrator(): void
    {
        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseRedirects('http://localhost/admin/login');
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * @test
     */
    public function it_lists_the_payment_gateways_and_shipment_templates_from_shipmondo(): void
    {
        $this->logIn();
        $this->respondWithGatewaysAndTemplates();

        $crawler = $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Shipmondo');
        self::assertSame(
            ['', '4711', '4712'],
            $crawler->filter(sprintf('select[name="payment_methods[%d]"] option', self::id($this->fixtures->paymentMethod)))->extract(['value']),
        );
        // the mapped gateway is preselected
        self::assertSame('4711', $crawler->filter('select[name^="payment_methods"] option[selected]')->attr('value'));
        self::assertSame(
            ['664116', '664115'],
            $crawler->filter(sprintf('select[name="shipping_methods[%d][]"] option', self::id($this->fixtures->shippingMethod)))->extract(['value']),
        );
        self::assertSelectorTextContains('body', 'Either you have not registered webhooks yet');
    }

    /**
     * @test
     */
    public function it_still_renders_the_page_when_the_shipmondo_api_fails(): void
    {
        $this->logIn();
        $this->shipmondo->respond('GET', 'payment_gateways', 401, ['error' => 'Invalid credentials']);

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.message.negative', 'Could not fetch data from the Shipmondo API. Please check your Shipmondo credentials.');
        // the webhooks can still be registered
        self::assertSelectorExists('a[href="/admin/shipmondo/register-webhooks"]');
    }

    /**
     * @test
     */
    public function it_maps_a_payment_method_to_a_shipmondo_payment_gateway(): void
    {
        $this->logIn();
        $this->respondWithGatewaysAndTemplates();

        $crawler = $this->browser->request('GET', '/admin/shipmondo');
        $form = $crawler->filter('select[name^="payment_methods"]')->closest('form')?->form();
        self::assertNotNull($form);
        self::choice($form, sprintf('payment_methods[%d]', self::id($this->fixtures->paymentMethod)))->setValue('4712');
        $this->browser->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame(4712, $this->paymentMethodShipmondoId());
    }

    /**
     * @test
     */
    public function it_maps_a_shipping_method_to_shipment_templates(): void
    {
        $this->logIn();
        $this->respondWithGatewaysAndTemplates();

        $crawler = $this->browser->request('GET', '/admin/shipmondo');
        $form = $crawler->filter('select[name^="shipping_methods"]')->closest('form')?->form();
        self::assertNotNull($form);
        self::choice($form, sprintf('shipping_methods[%d]', self::id($this->fixtures->shippingMethod)))->setValue(['664116', '664115']);
        $this->browser->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame([664116, 664115], $this->allowedShipmentTemplates());
    }

    /**
     * @test
     */
    public function it_removes_the_mapping_when_no_payment_gateway_is_selected(): void
    {
        self::skipUntilFixed(18);

        $this->logIn();
        $this->respondWithGatewaysAndTemplates();

        $crawler = $this->browser->request('GET', '/admin/shipmondo');
        $form = $crawler->filter('select[name^="payment_methods"]')->closest('form')?->form();
        self::assertNotNull($form);
        // "Select payment method..." — the page says to leave it empty when there is no equivalent gateway
        self::choice($form, sprintf('payment_methods[%d]', self::id($this->fixtures->paymentMethod)))->setValue('');
        $this->browser->submit($form);

        self::assertResponseIsSuccessful();
        self::assertNull($this->paymentMethodShipmondoId());
    }

    /**
     * @test
     */
    public function it_removes_the_shipment_templates_when_none_are_selected(): void
    {
        self::skipUntilFixed(18);

        $this->logIn();
        $this->fixtures->shippingMethod->setAllowedShipmentTemplates([664116]);
        $this->entityManager()->flush();
        $this->respondWithGatewaysAndTemplates();

        $crawler = $this->browser->request('GET', '/admin/shipmondo');
        $form = $crawler->filter('select[name^="shipping_methods"]')->closest('form')?->form();
        self::assertNotNull($form);
        self::choice($form, sprintf('shipping_methods[%d]', self::id($this->fixtures->shippingMethod)))->setValue([]);
        $this->browser->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->allowedShipmentTemplates());
    }

    /**
     * @test
     */
    public function it_explains_that_there_are_no_shipment_templates_in_shipmondo(): void
    {
        self::skipUntilFixed(19);

        $this->logIn();
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [], ['X-Total-Pages' => '1']);
        $this->shipmondo->respond('GET', 'shipment_templates', 200, [], ['X-Total-Pages' => '1']);

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'There are no configured payment gateways inside Shipmondo.');
        self::assertSelectorTextNotContains('body', 'setono_sylius_shipmondo.ui.');
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks(): void
    {
        $this->logIn();
        $this->respondToWebhookRegistration();

        $this->browser->request('GET', '/admin/shipmondo/register-webhooks');

        self::assertResponseRedirects('/admin/shipmondo');
        self::assertCount(11, $this->shipmondo->requests('POST', 'webhooks'));
        self::assertSame(1, self::nullableInt($this->database()->fetchOne('SELECT COUNT(*) FROM setono_sylius_shipmondo__registered_webhooks')));

        $this->respondWithGatewaysAndTemplates();
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.message.positive', 'Webhooks registered successfully');
        self::assertSelectorTextContains('body', 'Webhooks were registered on');
    }

    /**
     * @test
     */
    public function it_adds_the_shipmondo_page_to_the_admin_menu(): void
    {
        $this->logIn();

        $this->browser->request('GET', '/admin/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/admin/shipmondo"]');
    }

    private function logIn(): void
    {
        $this->loginAs($this->fixtures->admin(), 'admin');
    }

    private function respondWithGatewaysAndTemplates(): void
    {
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [
            ['id' => 4711, 'name' => 'Bank transfer', 'provider' => 'manual', 'merchant_number' => ''],
            ['id' => 4712, 'name' => 'Quickpay', 'provider' => 'quickpay', 'merchant_number' => '12345'],
        ], ['X-Total-Pages' => '1']);

        $this->shipmondo->respond('GET', 'shipment_templates', 200, [
            ['id' => 664116, 'name' => 'GLS pickup point (DK)', 'sender' => ['country_code' => 'DK'], 'receiver' => ['country_code' => 'DK'], 'parcels' => []],
            ['id' => 664115, 'name' => 'GLS pickup point (SE)', 'sender' => ['country_code' => 'DK'], 'receiver' => ['country_code' => 'SE'], 'parcels' => []],
        ], ['X-Total-Pages' => '1']);
    }

    private function respondToWebhookRegistration(): void
    {
        $this->shipmondo->respond('GET', 'webhooks', 200, [], ['X-Total-Pages' => '1']);
        $this->shipmondo->respond('POST', 'webhooks', 201, [
            'id' => 1,
            'endpoint' => 'https://localhost/webhook/shipmondo',
            'active' => true,
            'name' => 'Setono Sylius Shipmondo Plugin - [orders:create]',
            'action' => 'create',
            'resource_name' => 'Orders',
        ]);
    }

    private function paymentMethodShipmondoId(): ?int
    {
        $id = $this->database()->fetchOne('SELECT shipmondoId FROM sylius_payment_method WHERE code = ?', [ShopFixtures::PAYMENT_METHOD_CODE]);

        return self::nullableInt($id);
    }

    /**
     * @return list<int>
     */
    private function allowedShipmentTemplates(): array
    {
        $json = $this->database()->fetchOne('SELECT allowedShipmentTemplates FROM sylius_shipping_method WHERE code = ?', [ShopFixtures::SHIPPING_METHOD_CODE]);
        self::assertIsString($json);

        /** @var list<int> $templates */
        $templates = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $templates;
    }
}
