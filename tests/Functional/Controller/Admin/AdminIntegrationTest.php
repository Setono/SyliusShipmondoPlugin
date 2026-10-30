<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Controller\Admin;

use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;

/**
 * What the plugin adds to Sylius's own admin pages: grid columns and the shipping method form fields
 */
final class AdminIntegrationTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_shows_the_shipmondo_state_of_each_order_in_the_order_grid(): void
    {
        $this->logIn();
        $customer = $this->fixtures->createCustomer();
        $this->fixtures->createOrder($customer);
        $this->fixtures->markAsUploaded($this->fixtures->createOrder($customer), 1001);

        $crawler = $this->browser->request('GET', '/admin/orders/');

        self::assertResponseIsSuccessful();
        self::assertContains('Shipmondo state', $crawler->filter('table thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertSelectorTextContains('table tbody', 'Pending');
        self::assertSelectorTextContains('table tbody', 'Uploaded to Shipmondo');
    }

    /**
     * @test
     */
    public function it_shows_whether_a_shipping_method_delivers_to_pickup_points_in_the_shipping_method_grid(): void
    {
        $this->logIn();

        $crawler = $this->browser->request('GET', '/admin/shipping-methods/');

        self::assertResponseIsSuccessful();
        self::assertContains('Pickup point delivery', $crawler->filter('table thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertSelectorTextContains('table tbody', 'Yes (gls)');
    }

    /**
     * @test
     */
    public function it_requires_a_carrier_when_pickup_point_delivery_is_enabled(): void
    {
        $this->logIn();

        $form = $this->shippingMethodForm();
        self::choice($form, 'sylius_shipping_method[carrierCode]')->setValue('');
        $this->browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="sylius_shipping_method"]', 'When the pickup point delivery is set, you have to select a carrier.');
        self::assertSame([true, 'gls'], $this->pickupPointColumns());
    }

    /**
     * @test
     */
    public function it_saves_the_carrier_of_a_pickup_point_shipping_method(): void
    {
        $this->logIn();

        $form = $this->shippingMethodForm();
        self::choice($form, 'sylius_shipping_method[carrierCode]')->setValue('post_nord');
        $this->browser->submit($form);

        self::assertResponseRedirects();
        self::assertSame([true, 'post_nord'], $this->pickupPointColumns());
    }

    /**
     * @test
     */
    public function it_clears_the_carrier_when_pickup_point_delivery_is_disabled(): void
    {
        self::skipUntilFixed(21);

        $this->logIn();

        $form = $this->shippingMethodForm();
        self::choice($form, 'sylius_shipping_method[pickupPointDelivery]')->untick();
        $this->browser->submit($form);

        self::assertResponseRedirects();
        self::assertSame([false, null], $this->pickupPointColumns());
    }

    private function logIn(): void
    {
        $this->fixtures->createShop();
        $this->loginAs($this->fixtures->createAdmin(), 'admin');
    }

    private function shippingMethodForm(): Form
    {
        $crawler = $this->browser->request('GET', sprintf('/admin/shipping-methods/%d/edit', self::id($this->fixtures->shippingMethod)));
        self::assertResponseIsSuccessful();
        // the carrier field is toggled by the plugin's admin script
        self::assertStringContainsString('bundles/setonosyliusshipmondoplugin/js/carrier.js', (string) $this->browser->getResponse()->getContent());

        return $crawler->filter('form[name="sylius_shipping_method"]')->form();
    }

    /**
     * @return array{0: bool, 1: string|null} whether pickup point delivery is enabled, and the carrier code
     */
    private function pickupPointColumns(): array
    {
        $row = $this->observer()->fetchAssociative(
            'SELECT pickupPointDelivery, carrierCode FROM sylius_shipping_method WHERE code = ?',
            [ShopFixtures::SHIPPING_METHOD_CODE],
        );
        self::assertIsArray($row);
        $carrierCode = $row['carrierCode'];
        self::assertTrue(null === $carrierCode || is_string($carrierCode));

        return [1 === self::nullableInt($row['pickupPointDelivery']), $carrierCode];
    }
}
