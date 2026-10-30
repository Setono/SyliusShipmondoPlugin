<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Form\Extension;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Form\Extension\ShippingMethodTypeExtension;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\When;
use Symfony\Component\Validator\Validation;

final class ShippingMethodTypeExtensionTest extends TestCase
{
    private const CARRIERS = [
        'bpost' => 'bpost',
        'Bring' => 'bring',
        'Budbee' => 'budbee',
        'BWS (Blue Water Shipping)' => 'bws',
        'DAO' => 'dao',
        'DB Schenker' => 'db_schenker',
        'DFM' => 'dfm',
        'DHL Freight SE' => 'dhl_freight_se',
        'DHL Parcel' => 'dhl_parcel',
        'DSV Xpress' => 'dsv_xpress',
        'GLS' => 'gls',
        'GLS DE' => 'gls_de',
        'GLS PL' => 'gls_pl',
        'Helthjem' => 'helthjem',
        'Packeta' => 'packeta',
        'PDK' => 'pdk',
        'Post NL' => 'post_nl',
        'PostNord' => 'post_nord',
        'Posti' => 'posti',
        'UB DB Schenker SE' => 'ub_db_schenker_se',
        'UPS' => 'ups',
    ];

    /**
     * @test
     */
    public function it_extends_the_shipping_method_type(): void
    {
        self::assertSame([ShippingMethodType::class], [...ShippingMethodTypeExtension::getExtendedTypes()]);
    }

    /**
     * @test
     */
    public function it_adds_an_optional_pickup_point_delivery_checkbox(): void
    {
        $field = self::builder()->get('pickupPointDelivery');

        self::assertInstanceOf(CheckboxType::class, $field->getType()->getInnerType());
        self::assertSame('setono_sylius_shipmondo.form.shipping_method.pickup_point_delivery', $field->getOption('label'));
        self::assertFalse($field->getOption('required'));
    }

    /**
     * @test
     */
    public function it_adds_an_optional_carrier_code_choice_with_the_shipmondo_pickup_point_carriers(): void
    {
        $field = self::builder()->get('carrierCode');

        self::assertInstanceOf(ChoiceType::class, $field->getType()->getInnerType());
        self::assertSame(self::CARRIERS, $field->getOption('choices'));
        self::assertFalse($field->getOption('label'));
        self::assertSame('setono_sylius_shipmondo.form.shipping_method.carrier_code_placeholder', $field->getOption('placeholder'));
        self::assertFalse($field->getOption('required'));

        $constraints = $field->getOption('constraints');
        self::assertIsArray($constraints);
        self::assertCount(1, $constraints);

        $when = $constraints[0];
        self::assertInstanceOf(When::class, $when);
        self::assertSame('this.getParent().get("pickupPointDelivery").getData() === true', $when->expression);

        $nestedConstraints = $when->constraints;
        self::assertIsArray($nestedConstraints);
        self::assertCount(1, $nestedConstraints);

        $notBlank = $nestedConstraints[0];
        self::assertInstanceOf(NotBlank::class, $notBlank);
        self::assertSame('setono_sylius_shipmondo.shipping_method.carrier_code.not_blank', $notBlank->message);
        self::assertSame(['sylius'], $notBlank->groups);
    }

    /**
     * @test
     */
    public function it_requires_a_carrier_code_when_pickup_point_delivery_is_enabled(): void
    {
        $form = self::submit(['pickupPointDelivery' => '1', 'carrierCode' => '']);

        self::assertFalse($form->isValid());
        self::assertCount(1, $form->get('carrierCode')->getErrors());
        self::assertSame(
            'setono_sylius_shipmondo.shipping_method.carrier_code.not_blank',
            $form->get('carrierCode')->getErrors()[0]->getMessage(),
        );
    }

    /**
     * @test
     */
    public function it_accepts_a_carrier_code_when_pickup_point_delivery_is_enabled(): void
    {
        $form = self::submit(['pickupPointDelivery' => '1', 'carrierCode' => 'gls']);

        self::assertTrue($form->isValid());
        self::assertSame(['pickupPointDelivery' => true, 'carrierCode' => 'gls'], $form->getData());
    }

    /**
     * @test
     */
    public function it_does_not_require_a_carrier_code_without_pickup_point_delivery(): void
    {
        $form = self::submit(['carrierCode' => '']);

        self::assertTrue($form->isValid());
        self::assertSame(['pickupPointDelivery' => false, 'carrierCode' => null], $form->getData());
    }

    private static function builder(): FormBuilderInterface
    {
        $builder = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
            ->createBuilder(FormType::class, null, ['validation_groups' => ['sylius']])
        ;

        (new ShippingMethodTypeExtension())->buildForm($builder, []);

        return $builder;
    }

    /**
     * @param array<string, string> $data
     */
    private static function submit(array $data): FormInterface
    {
        $form = self::builder()->getForm();
        $form->submit($data);

        return $form;
    }
}
