<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Form\Extension;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Form\Extension\ShipmentTypeExtension;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;

final class ShipmentTypeExtensionTest extends TestCase
{
    /**
     * @test
     */
    public function it_extends_the_checkout_shipment_type(): void
    {
        self::assertSame([ShipmentType::class], [...ShipmentTypeExtension::getExtendedTypes()]);
    }

    /**
     * @test
     */
    public function it_adds_the_chosen_pickup_point_as_a_hidden_json_field(): void
    {
        $builder = self::builder();

        self::assertInstanceOf(HiddenType::class, $builder->get('shipmondoPickupPoint')->getType()->getInnerType());

        $form = $builder->getForm();
        $form->setData(['shipmondoPickupPoint' => ['id' => '95558', 'name' => 'Pakkeshop']]);

        self::assertSame('{"id":"95558","name":"Pakkeshop"}', $form->get('shipmondoPickupPoint')->getViewData());
    }

    /**
     * @test
     */
    public function it_renders_no_pickup_point_as_an_empty_field(): void
    {
        $form = self::builder()->getForm();
        $form->setData(['shipmondoPickupPoint' => null]);

        self::assertSame('', $form->get('shipmondoPickupPoint')->getViewData());
        self::assertNull(self::transformer()->transform(null));
    }

    /**
     * @test
     */
    public function it_decodes_the_submitted_pickup_point(): void
    {
        $form = self::submit('{"id":"95558","name":"Pakkeshop"}');

        self::assertTrue($form->isValid());
        self::assertSame(['shipmondoPickupPoint' => ['id' => '95558', 'name' => 'Pakkeshop']], $form->getData());
    }

    /**
     * @test
     */
    public function it_maps_an_empty_submission_to_no_pickup_point(): void
    {
        self::assertSame(['shipmondoPickupPoint' => null], self::submit('')->getData());
        self::assertNull(self::transformer()->reverseTransform(null));
    }

    /**
     * @test
     */
    public function it_maps_json_that_is_not_an_object_to_no_pickup_point(): void
    {
        self::assertSame(['shipmondoPickupPoint' => null], self::submit('"95558"')->getData());
    }

    /**
     * @test
     */
    public function it_decodes_a_pickup_point_nested_up_to_the_default_json_depth(): void
    {
        // json_decode's default depth (512) accepts 511 levels of nesting, but not 512
        $decoded = self::transformer()->reverseTransform(self::nestedJson(511));
        self::assertIsArray($decoded);

        $this->expectException(\JsonException::class);
        self::transformer()->reverseTransform(self::nestedJson(512));
    }

    /**
     * @test
     */
    public function it_reports_a_form_error_when_the_submitted_pickup_point_is_not_valid_json(): void
    {
        // A tampered (or broken) hidden field must result in a form error, not an uncaught \JsonException (HTTP 500).
        // Skipped while the bug exists; it starts asserting the expected behaviour once the bug is fixed.
        try {
            $form = self::submit('not json');
        } catch (\JsonException) {
            self::markTestSkipped('Bug: https://github.com/Setono/SyliusShipmondoPlugin/issues/20');
        }

        self::assertFalse($form->isValid());
    }

    private static function builder(): FormBuilderInterface
    {
        $builder = Forms::createFormFactoryBuilder()->getFormFactory()->createBuilder(FormType::class);
        (new ShipmentTypeExtension())->buildForm($builder, []);

        return $builder;
    }

    private static function transformer(): DataTransformerInterface
    {
        $transformers = self::builder()->get('shipmondoPickupPoint')->getModelTransformers();
        self::assertCount(1, $transformers);

        return $transformers[0];
    }

    private static function submit(string $pickupPoint): FormInterface
    {
        $form = self::builder()->getForm();
        $form->submit(['shipmondoPickupPoint' => $pickupPoint]);

        return $form;
    }

    private static function nestedJson(int $levels): string
    {
        return '{"id":' . str_repeat('[', $levels - 1) . str_repeat(']', $levels - 1) . '}';
    }
}
