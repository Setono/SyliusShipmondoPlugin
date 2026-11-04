<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Form\Extension;

use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Form\Extension\ShipmentTypeExtension;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodChoiceType;
use Sylius\Component\Core\Model\Shipment as BaseShipment;
use Sylius\Component\Core\Model\ShippingMethod as BaseShippingMethod;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Shipping\Model\ShippingMethodInterface;
use Sylius\Component\Shipping\Resolver\ShippingMethodsResolverInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeExtensionInterface;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Translation\IdentityTranslator;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Shipment;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\ShippingMethod;
use Tests\Setono\SyliusShipmondoPlugin\Support\PickupPoints;

/**
 * The checkout shipment form with the pickup point picker's field: what ends up on the shipment, and which errors the
 * shipping step shows. The translator returns the message keys
 */
final class ShipmentTypeExtensionTest extends TypeTestCase
{
    use ProphecyTrait;

    private const PICKUP_POINT_METHOD = 'gls_pickup_point';

    private const REGULAR_METHOD = 'ups';

    private const INVALID = 'setono_sylius_shipmondo.checkout.pickup_point.invalid';

    private const MISSING = 'setono_sylius_shipmondo.checkout.pickup_point.missing';

    /** @var ObjectProphecy<ShippingMethodsResolverInterface> */
    private ObjectProphecy $shippingMethodsResolver;

    /**
     * @test
     */
    public function it_stores_the_chosen_pickup_point_on_the_shipment(): void
    {
        $shipment = new Shipment();

        $form = $this->submit($shipment, self::PICKUP_POINT_METHOD, PickupPoints::JSON);

        self::assertTrue($form->isValid());
        self::assertEquals(PickupPoints::create(), $shipment->getShipmondoPickupPoint());
    }

    /**
     * A tampered request or a broken pickup point picker
     *
     * @test
     *
     * @dataProvider unreadablePickupPoints
     */
    public function it_shows_an_error_at_the_top_of_the_shipping_step_when_it_cannot_read_the_pickup_point(string $pickupPoint): void
    {
        $shipment = new Shipment();
        $shipment->setShipmondoPickupPoint(PickupPoints::create());

        $form = $this->submit($shipment, self::PICKUP_POINT_METHOD, $pickupPoint);

        self::assertErrors([self::INVALID], $form);
        self::assertEquals(PickupPoints::create(), $shipment->getShipmondoPickupPoint(), 'The shipment keeps its pickup point');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadablePickupPoints(): iterable
    {
        yield 'not JSON (#20)' => ['not json'];
        yield 'JSON with a missing key (#37)' => ['{"id":"96285"}'];
        yield 'JSON with a value of the wrong type (#37)' => [str_replace('"id":"96285"', '"id":96285', PickupPoints::JSON)];
        yield 'JSON that is not an object' => ['"96285"'];
    }

    /**
     * E.g. when the pickup point picker couldn't load the pickup points
     *
     * @test
     */
    public function it_shows_an_error_at_the_top_of_the_shipping_step_when_a_pickup_point_shipping_method_has_no_pickup_point(): void
    {
        $form = $this->submit(new Shipment(), self::PICKUP_POINT_METHOD, null);

        self::assertErrors([self::MISSING], $form);
    }

    /**
     * Pickup points that arrive after the customer selected another shipping method (#27), or a tampered request
     *
     * @test
     *
     * @dataProvider pickupPointsForAShippingMethodWithoutPickupPointDelivery
     */
    public function it_drops_the_pickup_point_of_a_shipping_method_without_pickup_point_delivery(?string $pickupPoint): void
    {
        $shipment = new Shipment();
        $shipment->setShipmondoPickupPoint(PickupPoints::create());

        $form = $this->submit($shipment, self::REGULAR_METHOD, $pickupPoint);

        self::assertTrue($form->isValid());
        self::assertNull($shipment->getShipmondoPickupPoint());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function pickupPointsForAShippingMethodWithoutPickupPointDelivery(): iterable
    {
        yield 'a pickup point' => [PickupPoints::JSON];
        yield 'one it cannot read' => ['not json'];
        yield 'none' => [null];
    }

    /**
     * @test
     */
    public function it_drops_the_pickup_point_of_a_shipping_method_without_the_plugins_pickup_point_delivery_setting(): void
    {
        $shipment = new Shipment();
        $shippingMethod = new BaseShippingMethod();
        self::name($shippingMethod, self::REGULAR_METHOD);
        $this->shippingMethodsResolver->getSupportedMethods(Argument::any())->willReturn([$shippingMethod]);

        $form = $this->submit($shipment, self::REGULAR_METHOD, PickupPoints::JSON);

        self::assertTrue($form->isValid());
        self::assertNull($shipment->getShipmondoPickupPoint());
    }

    /**
     * @test
     */
    public function it_drops_the_pickup_point_when_no_shipping_method_is_chosen(): void
    {
        $shipment = new Shipment();
        $shipment->setShipmondoPickupPoint(PickupPoints::create());

        $this->submit($shipment, null, PickupPoints::JSON);

        self::assertNull($shipment->getShipmondoPickupPoint());
    }

    /**
     * @test
     */
    public function it_leaves_a_shipment_alone_that_cannot_hold_a_pickup_point(): void
    {
        $form = $this->factory->create(ShipmentType::class, new BaseShipment(), ['data_class' => BaseShipment::class]);

        $form->submit(['method' => self::PICKUP_POINT_METHOD, 'shipmondoPickupPoint' => PickupPoints::JSON]);

        self::assertTrue($form->isValid());
    }

    /**
     * @test
     */
    public function it_accepts_the_field_the_pickup_point_picker_submits_without_rendering_it(): void
    {
        $field = $this->factory->create(ShipmentType::class, new Shipment())->get('shipmondoPickupPoint')->getConfig();

        self::assertInstanceOf(HiddenType::class, $field->getType()->getInnerType());
        self::assertFalse($field->getMapped(), 'The checkout parses the pickup point once the shipping method is known');
    }

    protected function setUp(): void
    {
        $this->shippingMethodsResolver = $this->prophesize(ShippingMethodsResolverInterface::class);
        $this->shippingMethodsResolver->supports(Argument::any())->willReturn(true);
        $this->shippingMethodsResolver->getSupportedMethods(Argument::any())->willReturn([
            self::shippingMethod(self::PICKUP_POINT_METHOD, true),
            self::shippingMethod(self::REGULAR_METHOD, false),
        ]);

        parent::setUp();
    }

    /**
     * @return list<FormExtensionInterface>
     */
    protected function getExtensions(): array
    {
        // The checkout shipment type adds Sylius' shipping method choice, which needs these services
        return [
            new PreloadedExtension([
                new ShipmentType(Shipment::class),
                new ShippingMethodChoiceType(
                    $this->shippingMethodsResolver->reveal(),
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
        return [new ShipmentTypeExtension(new IdentityTranslator())];
    }

    private function submit(Shipment $shipment, ?string $shippingMethod, ?string $pickupPoint): FormInterface
    {
        $form = $this->factory->create(ShipmentType::class, $shipment);
        $form->submit(['method' => $shippingMethod, 'shipmondoPickupPoint' => $pickupPoint]);

        return $form;
    }

    /**
     * Asserts the errors at the top of the form, i.e. on the root form, where the shipping step shows them
     *
     * @param list<string> $expected
     */
    private static function assertErrors(array $expected, FormInterface $form): void
    {
        self::assertFalse($form->isValid());

        $errors = [];
        foreach ($form->getErrors() as $error) {
            self::assertInstanceOf(FormError::class, $error);
            self::assertSame($error->getMessageTemplate(), $error->getMessage());
            $errors[] = $error->getMessage();
        }

        self::assertSame($expected, $errors);
    }

    private static function shippingMethod(string $code, bool $pickupPointDelivery): ShippingMethodInterface
    {
        $shippingMethod = new ShippingMethod();
        self::name($shippingMethod, $code);
        $shippingMethod->setPickupPointDelivery($pickupPointDelivery);

        return $shippingMethod;
    }

    /**
     * The choice of shipping methods reads their (translated) names
     */
    private static function name(ShippingMethodInterface $shippingMethod, string $code): void
    {
        $shippingMethod->setCode($code);
        $shippingMethod->setCurrentLocale('en_US');
        $shippingMethod->setFallbackLocale('en_US');
        $shippingMethod->setName($code);
    }
}
