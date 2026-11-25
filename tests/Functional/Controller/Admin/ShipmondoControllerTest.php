<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Controller\Admin;

use Symfony\Component\DomCrawler\Crawler;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;

/**
 * The admin "Shipmondo" page: mapping payment and shipping methods to Shipmondo and registering webhooks
 */
final class ShipmondoControllerTest extends FunctionalTestCase
{
    private const WEBHOOKS_NOT_REGISTERED = 'The webhooks are not registered yet, or not since the plugin started recording where Shipmondo sends them.';

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
        self::assertSelectorTextContains('body', self::WEBHOOKS_NOT_REGISTERED);
        // nothing to compare this shop's webhooks with: an unstubbed request for them would fail the test
        self::assertSame([], $this->shipmondo->requests('GET', 'webhooks'));
        // every mapping exists in Shipmondo
        self::assertSelectorNotExists('.message.warning');
        self::assertSelectorNotExists('tr.warning');
    }

    /**
     * @test
     */
    public function it_still_renders_the_page_when_the_shipmondo_api_fails(): void
    {
        $this->logIn();
        $this->shipmondo->respond('GET', 'payment_gateways', 401, ['error' => 'Invalid credentials']);
        $this->shipmondo->respond('GET', 'shipment_templates', 401, ['error' => 'Invalid credentials']);

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        // both failed for the same reason, so one message says so
        self::assertSelectorCount(1, '.message.negative');
        self::assertSelectorTextContains('.message.negative p', 'Could not fetch the payment gateways and shipment templates from Shipmondo. Shipmondo did not accept the API credentials.');
        // neither table says Shipmondo has none
        self::assertSelectorTextContains('body', 'The payment gateways could not be loaded from Shipmondo');
        self::assertSelectorTextContains('body', 'The shipment templates could not be loaded from Shipmondo');
        self::assertSelectorTextNotContains('body', 'There are no');
        // the webhooks can still be registered
        self::assertSelectorExists('a[href="/admin/shipmondo/register-webhooks"]');
        // without Shipmondo's payment gateways and shipment templates, no mapping is known to be stale
        self::assertSelectorNotExists('.message.warning');
        self::assertSelectorNotExists('form select');
    }

    /**
     * @test
     */
    public function it_does_not_blame_the_credentials_when_shipmondo_is_down(): void
    {
        $this->logIn();
        $this->shipmondo->failToConnect('GET', 'payment_gateways');
        $this->shipmondo->failToConnect('GET', 'shipment_templates');

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.message.negative p', 'Could not fetch the payment gateways and shipment templates from Shipmondo. Shipmondo could not be reached, or had a server error.');
        self::assertSelectorTextNotContains('.message.negative', 'credentials');
    }

    /**
     * @test
     */
    public function it_says_what_could_not_be_fetched_when_each_list_failed_for_its_own_reason(): void
    {
        $this->logIn();
        $this->shipmondo->respond('GET', 'payment_gateways', 429, ['error' => 'Too many requests']);
        $this->shipmondo->failToConnect('GET', 'shipment_templates');

        $crawler = $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSame([
            'Could not fetch the payment gateways from Shipmondo. Shipmondo is limiting how often the store may call it. Try again in a minute.',
            'Could not fetch the shipment templates from Shipmondo. Shipmondo could not be reached, or had a server error. Try again later.',
        ], $crawler->filter('.message.negative p')->each(static fn (Crawler $message): string => $message->text()));
    }

    /**
     * Each list is fetched on its own, so the shipping methods can still be mapped when the payment gateways couldn't
     * be fetched
     *
     * @test
     */
    public function it_says_the_payment_gateways_could_not_be_loaded_instead_of_that_shipmondo_has_none(): void
    {
        $this->logIn();
        $this->shipmondo->failToConnect('GET', 'payment_gateways');
        $this->respondWithShipmentTemplates();

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.message.negative p', 'Could not fetch the payment gateways from Shipmondo. Shipmondo could not be reached, or had a server error. Try again later.');
        self::assertSelectorTextContains('body', 'The payment gateways could not be loaded from Shipmondo, so the payment methods can\'t be mapped now.');
        self::assertSelectorTextNotContains('body', 'There are no configured payment gateways inside Shipmondo');
        self::assertSelectorNotExists('select[name^="payment_methods"]');
        self::assertSelectorExists(sprintf('select[name="shipping_methods[%d][]"] option[value="664116"]', self::id($this->fixtures->shippingMethod)));
    }

    /**
     * The payment methods can still be mapped when only the shipment templates couldn't be fetched
     *
     * @test
     */
    public function it_says_the_shipment_templates_could_not_be_loaded_instead_of_that_shipmondo_has_none(): void
    {
        $this->logIn();
        $this->respondWithPaymentGateways();
        $this->shipmondo->respond('GET', 'shipment_templates', 503, null);

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('.message.negative p', 'Could not fetch the shipment templates from Shipmondo. Shipmondo could not be reached, or had a server error. Try again later.');
        self::assertSelectorTextContains('body', 'The shipment templates could not be loaded from Shipmondo, so the shipping methods can\'t be mapped now.');
        self::assertSelectorTextNotContains('body', 'There are no shipment templates inside Shipmondo');
        self::assertSelectorNotExists('select[name^="shipping_methods"]');
        self::assertSelectorExists(sprintf('select[name="payment_methods[%d]"] option[value="4712"]', self::id($this->fixtures->paymentMethod)));
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
     * Saving one payment method's mapping keeps another's to a payment gateway Shipmondo doesn't list, e.g. one deleted
     * in Shipmondo: the page shows it as such, selected
     *
     * @test
     */
    public function it_keeps_a_mapping_to_a_payment_gateway_shipmondo_does_not_list(): void
    {
        $this->logIn();
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [
            ['id' => 4712, 'name' => 'Quickpay', 'provider' => 'quickpay', 'merchant_number' => '12345'],
        ], ['X-Total-Pages' => '1']);
        $this->shipmondo->respond('GET', 'shipment_templates', 200, [], ['X-Total-Pages' => '1']);

        $crawler = $this->browser->request('GET', '/admin/shipmondo');
        $option = $crawler->filter(sprintf('select[name="payment_methods[%d]"] option[selected]', self::id($this->fixtures->paymentMethod)));
        self::assertSame(['4711'], $option->extract(['value']));
        self::assertSame('Not in Shipmondo (id 4711)', $option->text());

        $form = $crawler->filter('select[name^="payment_methods"]')->closest('form')?->form();
        self::assertNotNull($form);
        $this->browser->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame(ShopFixtures::PAYMENT_METHOD_SHIPMONDO_ID, $this->paymentMethodShipmondoId());
    }

    /**
     * @test
     */
    public function it_keeps_an_allowed_shipment_template_shipmondo_does_not_list(): void
    {
        $this->logIn();
        $this->fixtures->shippingMethod->setAllowedShipmentTemplates([999, 664116]);
        $this->entityManager()->flush();
        $this->respondWithGatewaysAndTemplates();

        $crawler = $this->browser->request('GET', '/admin/shipmondo');
        $selected = $crawler->filter(sprintf('select[name="shipping_methods[%d][]"] option[selected]', self::id($this->fixtures->shippingMethod)));
        self::assertSame(['664116', '999'], $selected->extract(['value']));
        self::assertSame('Not in Shipmondo (id 999)', $selected->last()->text());

        $form = $crawler->filter('select[name^="shipping_methods"]')->closest('form')?->form();
        self::assertNotNull($form);
        $this->browser->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame([664116, 999], $this->allowedShipmentTemplates());
    }

    /**
     * @test
     */
    public function it_flags_a_payment_method_mapped_to_a_payment_gateway_that_no_longer_exists_in_shipmondo(): void
    {
        $this->logIn();
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [
            ['id' => 4712, 'name' => 'Quickpay', 'provider' => 'quickpay', 'merchant_number' => '12345'],
        ], ['X-Total-Pages' => '1']);
        $this->shipmondo->respond('GET', 'shipment_templates', 200, [], ['X-Total-Pages' => '1']);

        $crawler = $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        $warning = $crawler->filter('.message.warning');
        self::assertCount(1, $warning);
        self::assertStringContainsString('These payment methods are mapped to a payment gateway that no longer exists in Shipmondo', $warning->text());
        self::assertSame(['Bank transfer'], $warning->filter('li')->extract(['_text']));
        self::assertSame(['warning'], $crawler->filter(sprintf('select[name="payment_methods[%d]"]', self::id($this->fixtures->paymentMethod)))->closest('tr')?->extract(['class']));
    }

    /**
     * The payment methods are still listed when only the shipment templates couldn't be fetched, so saving them must
     * keep a mapping to a payment gateway that no longer exists in Shipmondo
     *
     * @test
     */
    public function it_keeps_flagging_a_stale_payment_gateway_when_only_the_shipment_templates_could_not_be_fetched(): void
    {
        $this->logIn();
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [
            ['id' => 4712, 'name' => 'Quickpay', 'provider' => 'quickpay', 'merchant_number' => '12345'],
        ], ['X-Total-Pages' => '1']);
        $this->shipmondo->respond('GET', 'shipment_templates', 401, ['error' => 'Invalid credentials']);

        $crawler = $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.message.negative p', 'Could not fetch the shipment templates from Shipmondo.');
        self::assertSame(['Bank transfer'], $crawler->filter('.message.warning li')->extract(['_text']));
        $option = $crawler->filter(sprintf('select[name="payment_methods[%d]"] option[selected]', self::id($this->fixtures->paymentMethod)));
        self::assertSame(['4711'], $option->extract(['value']));
    }

    /**
     * @test
     */
    public function it_flags_a_shipping_method_that_allows_shipment_templates_that_no_longer_exist_in_shipmondo(): void
    {
        $this->logIn();
        $this->fixtures->shippingMethod->setAllowedShipmentTemplates([999, 664116]);
        $this->entityManager()->flush();
        $this->respondWithGatewaysAndTemplates();

        $crawler = $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        $warning = $crawler->filter('.message.warning');
        self::assertCount(1, $warning);
        self::assertStringContainsString('These shipping methods allow shipment templates that no longer exist in Shipmondo', $warning->text());
        self::assertSame(['GLS pickup point'], $warning->filter('li')->extract(['_text']));
        self::assertSame(['warning'], $crawler->filter(sprintf('select[name="shipping_methods[%d][]"]', self::id($this->fixtures->shippingMethod)))->closest('tr')?->extract(['class']));
    }

    /**
     * A shop that deleted its last payment gateway in Shipmondo still has to be able to remove the stale mapping, or
     * every upload paid with that payment method logs an error
     *
     * @test
     */
    public function it_lets_the_admin_remove_a_stale_payment_gateway_mapping_when_shipmondo_has_no_payment_gateways(): void
    {
        $this->logIn();
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [], ['X-Total-Pages' => '1']);
        $this->shipmondo->respond('GET', 'shipment_templates', 200, [], ['X-Total-Pages' => '1']);

        $crawler = $this->browser->request('GET', '/admin/shipmondo');

        self::assertSelectorTextContains('body', 'There are no configured payment gateways inside Shipmondo.');
        $select = sprintf('select[name="payment_methods[%d]"]', self::id($this->fixtures->paymentMethod));
        self::assertSame(['', '4711'], $crawler->filter($select . ' option')->extract(['value']));
        self::assertSame('Not in Shipmondo (id 4711)', $crawler->filter($select . ' option[selected]')->text());

        $form = $crawler->filter($select)->closest('form')?->form();
        self::assertNotNull($form);
        self::choice($form, sprintf('payment_methods[%d]', self::id($this->fixtures->paymentMethod)))->setValue('');
        $this->browser->submit($form);

        self::assertResponseIsSuccessful();
        self::assertNull($this->paymentMethodShipmondoId());
        self::assertSelectorNotExists('.message.warning');
        self::assertSelectorNotExists('select[name^="payment_methods"]');
    }

    /**
     * @test
     */
    public function it_lets_the_admin_remove_stale_shipment_templates_when_shipmondo_has_no_shipment_templates(): void
    {
        $this->logIn();
        $this->fixtures->paymentMethod->setShipmondoId(null);
        $this->fixtures->shippingMethod->setAllowedShipmentTemplates([999]);
        $this->entityManager()->flush();
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [], ['X-Total-Pages' => '1']);
        $this->shipmondo->respond('GET', 'shipment_templates', 200, [], ['X-Total-Pages' => '1']);

        $crawler = $this->browser->request('GET', '/admin/shipmondo');

        self::assertSelectorTextContains('body', 'There are no shipment templates inside Shipmondo.');
        $select = sprintf('select[name="shipping_methods[%d][]"]', self::id($this->fixtures->shippingMethod));
        self::assertSame('Not in Shipmondo (id 999)', $crawler->filter($select . ' option[selected]')->text());

        $form = $crawler->filter($select)->closest('form')?->form();
        self::assertNotNull($form);
        self::choice($form, sprintf('shipping_methods[%d]', self::id($this->fixtures->shippingMethod)))->setValue([]);
        $this->browser->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->allowedShipmentTemplates());
        self::assertSelectorNotExists('.message.warning');
        self::assertSelectorNotExists('select[name^="shipping_methods"]');
    }

    /**
     * @test
     */
    public function it_explains_that_there_are_no_shipment_templates_in_shipmondo(): void
    {
        $this->logIn();
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [], ['X-Total-Pages' => '1']);
        $this->shipmondo->respond('GET', 'shipment_templates', 200, [], ['X-Total-Pages' => '1']);

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'There are no configured payment gateways inside Shipmondo.');
        self::assertSelectorTextContains('body', 'There are no shipment templates inside Shipmondo. Go to Shipmondo and create your first shipment template.');
        self::assertSelectorTextNotContains('body', 'setono_sylius_shipmondo.ui.');
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks_at_the_host_the_admin_is_opened_at(): void
    {
        $this->logIn();
        $this->openTheAdminAt('shop.example.com');
        $this->respondToWebhookRegistration();
        // the page then compares this shop's webhooks in Shipmondo, which are the ones registered
        $this->shipmondo->respond('GET', 'webhooks', 200, $this->wantedWebhooks('http://shop.example.com/webhook/shipmondo'), ['X-Total-Pages' => '1']);

        $this->browser->request('GET', '/admin/shipmondo/register-webhooks');

        self::assertResponseRedirects('/admin/shipmondo');
        // one per action the plugin's webhook handlers handle
        $endpoints = array_map(
            static fn ($request): mixed => ShipmondoApiStub::jsonBody($request)['endpoint'],
            $this->shipmondo->requests('POST', 'webhooks'),
        );
        self::assertSame(array_fill(0, 5, 'http://shop.example.com/webhook/shipmondo'), $endpoints);
        self::assertSame(1, self::nullableInt($this->database()->fetchOne('SELECT COUNT(*) FROM setono_sylius_shipmondo__registered_webhooks')));

        $this->respondWithGatewaysAndTemplates();
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.message.positive', 'Webhooks registered successfully');
        self::assertSelectorTextContains('body', 'The webhooks were last registered at http://shop.example.com/webhook/shipmondo on');
        self::assertSelectorTextContains('body', 'This shop\'s webhooks in Shipmondo match the ones the plugin handles.');
        self::assertSelectorTextNotContains('body', self::WEBHOOKS_NOT_REGISTERED);
    }

    /**
     * @test
     */
    public function it_shows_that_this_shops_webhooks_in_shipmondo_match_and_where_they_were_last_registered(): void
    {
        $this->logIn();
        $this->recordWebhookRegistration(self::WEBHOOK_ENDPOINT, '2026-03-04 05:06:07');
        $this->respondWithGatewaysAndTemplates();
        $this->shipmondo->respond('GET', 'webhooks', 200, $this->wantedWebhooks(self::WEBHOOK_ENDPOINT), ['X-Total-Pages' => '1']);

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'The webhooks were last registered at https://shop.example.com/webhook/shipmondo on March 4, 2026');
        self::assertSelectorTextContains('.message.positive', 'This shop\'s webhooks in Shipmondo match the ones the plugin handles.');
        self::assertSelectorNotExists('.message.negative');
        self::assertSelectorNotExists('.message.warning');
    }

    /**
     * E.g. after an upgrade of the plugin that handles a new action, before the webhooks are registered again
     *
     * @test
     */
    public function it_shows_how_this_shops_webhooks_in_shipmondo_differ_from_the_wanted_ones(): void
    {
        $this->logIn();
        $this->recordWebhookRegistration(self::WEBHOOK_ENDPOINT);
        $this->respondWithGatewaysAndTemplates();
        $wanted = $this->wantedWebhooks(self::WEBHOOK_ENDPOINT);
        $missing = $wanted[0];
        $inactive = ['id' => 8001, 'active' => false] + $wanted[1];
        $this->shipmondo->respond('GET', 'webhooks', 200, [...array_slice($wanted, 1), $inactive], ['X-Total-Pages' => '1']);

        $crawler = $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.message.warning', 'This shop\'s webhooks in Shipmondo don\'t match the ones the plugin handles:');
        self::assertSame([
            sprintf('Missing: %s', $missing['name']),
            sprintf('To remove: %s, sent to https://shop.example.com/webhook/shipmondo, inactive', $inactive['name']),
        ], $crawler->filter('.message.warning li')->each(static fn (Crawler $item): string => trim($item->text())));
        self::assertSelectorTextContains('.message.warning', 'Register them again with the button below, or with setono:sylius-shipmondo:register-webhooks.');
        // comparing changes nothing
        self::assertSame(['GET webhooks'], array_map(
            static fn ($request): string => $request->getMethod() . ' webhooks',
            $this->shipmondo->requests(null, 'webhooks'),
        ));
    }

    /**
     * @test
     */
    public function it_says_this_shops_webhooks_could_not_be_loaded_and_why(): void
    {
        $this->logIn();
        $this->recordWebhookRegistration(self::WEBHOOK_ENDPOINT);
        $this->respondWithGatewaysAndTemplates();
        $this->shipmondo->failToConnect('GET', 'webhooks');

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.message.negative');
        self::assertSelectorTextContains('.message.negative p', 'Could not fetch this shop\'s webhooks from Shipmondo. Shipmondo could not be reached, or had a server error.');
        self::assertSelectorTextContains('body', 'This shop\'s webhooks could not be loaded from Shipmondo, so whether they match the ones the plugin handles is unknown now.');
        self::assertSelectorTextContains('body', 'The webhooks were last registered at https://shop.example.com/webhook/shipmondo');
        // the other lists are still shown
        self::assertSelectorExists('select[name^="payment_methods"]');
    }

    /**
     * @test
     */
    public function it_says_once_when_nothing_could_be_fetched_from_shipmondo_for_the_same_reason(): void
    {
        $this->logIn();
        $this->recordWebhookRegistration(self::WEBHOOK_ENDPOINT);
        foreach (['payment_gateways', 'shipment_templates', 'webhooks'] as $list) {
            $this->shipmondo->respond('GET', $list, 401, ['error' => 'Invalid credentials']);
        }

        $this->browser->request('GET', '/admin/shipmondo');

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(1, '.message.negative');
        self::assertSelectorTextContains('.message.negative p', 'Could not fetch the payment gateways, shipment templates and this shop\'s webhooks from Shipmondo. Shipmondo did not accept the API credentials.');
    }

    /**
     * @test
     */
    public function it_explains_why_the_webhooks_could_not_be_registered(): void
    {
        $this->logIn();
        $this->openTheAdminAt('shop.example.com');
        $this->shipmondo->respond('GET', 'webhooks', 401, ['error' => 'Invalid credentials']);

        $this->browser->request('GET', '/admin/shipmondo/register-webhooks');

        self::assertResponseRedirects('/admin/shipmondo');
        self::assertSame(0, self::nullableInt($this->database()->fetchOne('SELECT COUNT(*) FROM setono_sylius_shipmondo__registered_webhooks')));

        $this->respondWithGatewaysAndTemplates();
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.message.negative', 'The webhooks could not be registered, so this shop\'s webhooks in Shipmondo may still differ from the ones the plugin handles. Register them again once the problem is solved. Shipmondo did not accept the API credentials.');
        self::assertSelectorNotExists('.message.positive');
        self::assertSelectorTextContains('body', self::WEBHOOKS_NOT_REGISTERED);
    }

    /**
     * Registering replaces this shop's webhooks, so registering them where Shipmondo can't send them, e.g. on a
     * developer's machine with the production credentials, would also remove the ones that work
     *
     * @test
     *
     * @dataProvider hostsShipmondoCannotReach
     */
    public function it_registers_no_webhooks_when_the_admin_is_opened_at_a_host_shipmondo_cannot_reach(string $host, string $endpoint): void
    {
        $this->logIn();
        $this->openTheAdminAt($host);

        $this->browser->request('GET', '/admin/shipmondo/register-webhooks');

        self::assertResponseRedirects('/admin/shipmondo');
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame(0, self::nullableInt($this->database()->fetchOne('SELECT COUNT(*) FROM setono_sylius_shipmondo__registered_webhooks')));

        $this->respondWithGatewaysAndTemplates();
        $this->browser->followRedirect();

        self::assertSelectorTextContains('.message.negative', sprintf('The webhooks were not registered, because Shipmondo can\'t reach their endpoint, %s. Open the admin at the shop\'s hostname and register them there. The webhooks registered before are kept.', $endpoint));
        self::assertSelectorNotExists('.message.positive');
        self::assertSelectorTextContains('body', self::WEBHOOKS_NOT_REGISTERED);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hostsShipmondoCannotReach(): iterable
    {
        yield 'localhost' => ['localhost', 'http://localhost/webhook/shipmondo'];
        // e.g. the address `symfony serve` serves a local app at
        yield 'a loopback address' => ['127.0.0.1:8000', 'http://127.0.0.1:8000/webhook/shipmondo'];
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

    /**
     * The browser sends its requests to this host (and port) instead of localhost
     */
    private function openTheAdminAt(string $host): void
    {
        $this->browser->setServerParameter('HTTP_HOST', $host);
    }

    private function respondWithGatewaysAndTemplates(): void
    {
        $this->respondWithPaymentGateways();
        $this->respondWithShipmentTemplates();
    }

    private function respondWithPaymentGateways(): void
    {
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [
            ['id' => 4711, 'name' => 'Bank transfer', 'provider' => 'manual', 'merchant_number' => ''],
            ['id' => 4712, 'name' => 'Quickpay', 'provider' => 'quickpay', 'merchant_number' => '12345'],
        ], ['X-Total-Pages' => '1']);
    }

    private function respondWithShipmentTemplates(): void
    {
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
            'endpoint' => 'http://shop.example.com/webhook/shipmondo',
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
