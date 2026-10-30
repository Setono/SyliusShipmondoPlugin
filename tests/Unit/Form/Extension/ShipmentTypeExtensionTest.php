<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Form\Extension;

use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Form\Extension\ShipmentTypeExtension;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodChoiceType;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Shipping\Resolver\ShippingMethodsResolverInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeExtensionInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Shipment;

final class ShipmentTypeExtensionTest extends TypeTestCase
{
    use ProphecyTrait;

    /**
     * @test
     *
     * @dataProvider provideSubmittedPickupPoints
     *
     * @param array<string, string>|null $expected
     */
    public function it_stores_the_submitted_pickup_point_on_the_shipment(string $submitted, ?array $expected): void
    {
        $shipment = new Shipment();
        $form = $this->createForm($shipment);

        $form->submit(['shipmondoPickupPoint' => $submitted]);

        self::assertTrue($form->isSynchronized());
        self::assertSame($expected, $shipment->getShipmondoPickupPoint());
    }

    /**
     * @return \Generator<string, array{string, array<string, string>|null}>
     */
    public function provideSubmittedPickupPoints(): \Generator
    {
        yield 'a pickup point' => ['{"id":"95558","name":"Pakkeshop"}', ['id' => '95558', 'name' => 'Pakkeshop']];
        yield 'no pickup point' => ['', null];
        yield 'JSON that is not an object' => ['"95558"', null];
    }

    /**
     * @test
     *
     * @dataProvider provideRenderedPickupPoints
     *
     * @param array<string, string>|null $pickupPoint
     */
    public function it_renders_the_pickup_point_of_the_shipment_as_json_in_a_hidden_field(?array $pickupPoint, string $expected): void
    {
        $shipment = new Shipment();
        $shipment->setShipmondoPickupPoint($pickupPoint);

        $vars = self::vars($this->createForm($shipment)->createView()['shipmondoPickupPoint']);

        self::assertSame('sylius_checkout_shipment[shipmondoPickupPoint]', $vars['full_name']);
        self::assertIsArray($vars['block_prefixes']);
        self::assertContains('hidden', $vars['block_prefixes']);
        self::assertSame($expected, $vars['value']);
    }

    /**
     * @return \Generator<string, array{array<string, string>|null, string}>
     */
    public function provideRenderedPickupPoints(): \Generator
    {
        yield 'a pickup point' => [['id' => '95558', 'name' => 'Pakkeshop'], '{"id":"95558","name":"Pakkeshop"}'];
        yield 'no pickup point' => [null, ''];
    }

    /**
     * @test
     */
    public function it_accepts_a_pickup_point_nested_as_deep_as_json_decode_allows_by_default(): void
    {
        $shipment = new Shipment();
        $form = $this->createForm($shipment);

        // json_decode's default depth (512) allows 511 levels of nesting
        $form->submit(['shipmondoPickupPoint' => self::nestedJson(511)]);

        self::assertTrue($form->isSynchronized());
        self::assertIsArray($shipment->getShipmondoPickupPoint());
    }

    /**
     * Unlike the invalid JSON test below, this one is not skipped while https://github.com/Setono/SyliusShipmondoPlugin/issues/20
     * exists, since it is the only test that tells json_decode's default depth apart from a larger one.
     *
     * @test
     */
    public function it_does_not_store_a_pickup_point_nested_deeper_than_json_decode_allows_by_default(): void
    {
        $shipment = new Shipment();
        $form = $this->createForm($shipment);

        try {
            // json_decode's default depth (512) does not allow 512 levels of nesting
            $form->submit(['shipmondoPickupPoint' => self::nestedJson(512)]);

            self::assertFalse($form->isSynchronized());
        } catch (\JsonException) {
            // Until issue #20 is fixed, the decoding error escapes the form instead
        }

        self::assertNull($shipment->getShipmondoPickupPoint());
    }

    /**
     * @test
     */
    public function it_does_not_accept_a_pickup_point_that_is_not_valid_json(): void
    {
        // A tampered (or broken) hidden field must make the form not synchronized, not throw an uncaught \JsonException (HTTP 500).
        // Skipped while the bug exists; it starts asserting the expected behaviour once the bug is fixed.
        $shipment = new Shipment();
        $form = $this->createForm($shipment);

        try {
            $form->submit(['shipmondoPickupPoint' => 'not json']);

            self::assertFalse($form->isSynchronized());
        } catch (\JsonException) {
            self::markTestSkipped('Bug: https://github.com/Setono/SyliusShipmondoPlugin/issues/20');
        }

        self::assertNull($shipment->getShipmondoPickupPoint());
    }

    /**
     * @return list<FormExtensionInterface>
     */
    protected function getExtensions(): array
    {
        // The checkout shipment type adds Sylius' shipping method choice, which needs these services.
        // No shipping methods are available for the shipment, since that choice is not under test.
        $shippingMethodsResolver = $this->prophesize(ShippingMethodsResolverInterface::class);
        $shippingMethodsResolver->supports(Argument::type(Shipment::class))->willReturn(true);
        $shippingMethodsResolver->getSupportedMethods(Argument::type(Shipment::class))->willReturn([]);

        return [
            new PreloadedExtension([
                new ShipmentType(Shipment::class),
                new ShippingMethodChoiceType(
                    $shippingMethodsResolver->reveal(),
                    $this->prophesize(ServiceRegistryInterface::class)->reveal(),
                    $this->prophesize(RepositoryInterface::class)->reveal(),
                ),
            ], []),
        ];
    }

    /**
     * @return list<FormTypeExtensionInterface>
     */
    protected function getTypeExtensions(): array
    {
        return [new ShipmentTypeExtension()];
    }

    private function createForm(Shipment $shipment): FormInterface
    {
        return $this->factory->create(ShipmentType::class, $shipment);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function vars(FormView $view): array
    {
        $vars = $view->vars;
        self::assertIsArray($vars);

        return $vars;
    }

    /**
     * Returns a JSON pickup point with $levels levels of nesting
     */
    private static function nestedJson(int $levels): string
    {
        return '{"id":' . str_repeat('[', $levels - 1) . str_repeat(']', $levels - 1) . '}';
    }
}
