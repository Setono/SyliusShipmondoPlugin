<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Form\Extension;

use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Form\Extension\ShippingMethodTypeExtension;
use Sylius\Bundle\ResourceBundle\Form\Extension\CollectionTypeExtension;
use Sylius\Bundle\ResourceBundle\Form\Registry\FormTypeRegistryInterface;
use Sylius\Bundle\ResourceBundle\Form\Type\ResourceTranslationsType;
use Sylius\Bundle\ShippingBundle\Form\Type\CalculatorChoiceType;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingCategoryChoiceType;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodRuleChoiceType;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodRuleCollectionType;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodRuleType;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodTranslationType;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodType;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Component\Shipping\Model\ShippingMethodRule;
use Sylius\Component\Shipping\Model\ShippingMethodTranslation;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Translation\Provider\TranslationLocaleProviderInterface;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormExtensionInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormTypeExtensionInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\When;
use Symfony\Component\Validator\Validation;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\ShippingMethod;

final class ShippingMethodTypeExtensionTest extends TypeTestCase
{
    use ProphecyTrait;

    /**
     * The pickup point carriers of the Shipmondo API, labelled as Shipmondo labels them
     */
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
     *
     * @dataProvider provideSubmittedPickupPointSettings
     *
     * @param array<string, string> $formData
     */
    public function it_stores_the_submitted_pickup_point_settings_on_the_shipping_method(
        array $formData,
        bool $expectedPickupPointDelivery,
        ?string $expectedCarrierCode,
    ): void {
        $shippingMethod = new ShippingMethod();
        $form = $this->createForm($shippingMethod);

        $form->submit($formData);

        self::assertTrue($form->isSynchronized());
        self::assertSame($expectedPickupPointDelivery, $shippingMethod->isPickupPointDelivery());
        self::assertSame($expectedCarrierCode, $shippingMethod->getCarrierCode());
    }

    /**
     * @return \Generator<string, array{array<string, string>, bool, string|null}>
     */
    public function provideSubmittedPickupPointSettings(): \Generator
    {
        yield 'pickup point delivery with a carrier' => [['pickupPointDelivery' => '1', 'carrierCode' => 'gls'], true, 'gls'];
        yield 'pickup point delivery without a carrier' => [['pickupPointDelivery' => '1', 'carrierCode' => ''], true, null];
        yield 'no pickup point delivery' => [['carrierCode' => ''], false, null];
    }

    /**
     * @test
     *
     * @dataProvider provideRenderedPickupPointSettings
     */
    public function it_renders_the_pickup_point_settings_of_the_shipping_method(
        bool $pickupPointDelivery,
        ?string $carrierCode,
        string $expectedCarrierValue,
    ): void {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setPickupPointDelivery($pickupPointDelivery);
        $shippingMethod->setCarrierCode($carrierCode);

        $view = $this->createForm($shippingMethod)->createView();

        self::assertSame($pickupPointDelivery, self::vars($view['pickupPointDelivery'])['checked']);
        self::assertSame($expectedCarrierValue, self::vars($view['carrierCode'])['value']);
    }

    /**
     * @return \Generator<string, array{bool, string|null, string}>
     */
    public function provideRenderedPickupPointSettings(): \Generator
    {
        yield 'pickup point delivery with a carrier' => [true, 'gls', 'gls'];
        yield 'no pickup point delivery' => [false, null, ''];
    }

    /**
     * @test
     */
    public function it_renders_an_optional_pickup_point_delivery_checkbox(): void
    {
        $vars = self::vars($this->createForm(new ShippingMethod())->createView()['pickupPointDelivery']);

        self::assertSame('sylius_shipping_method[pickupPointDelivery]', $vars['full_name']);
        self::assertIsArray($vars['block_prefixes']);
        self::assertContains('checkbox', $vars['block_prefixes']);
        self::assertSame('setono_sylius_shipmondo.form.shipping_method.pickup_point_delivery', $vars['label']);
        self::assertFalse($vars['required']);
    }

    /**
     * @test
     */
    public function it_renders_an_optional_unlabelled_choice_of_the_shipmondo_pickup_point_carriers(): void
    {
        $vars = self::vars($this->createForm(new ShippingMethod())->createView()['carrierCode']);

        self::assertSame('sylius_shipping_method[carrierCode]', $vars['full_name']);
        self::assertIsArray($vars['block_prefixes']);
        self::assertContains('choice', $vars['block_prefixes']);
        self::assertSame(self::CARRIERS, self::choices($vars));
        self::assertSame('setono_sylius_shipmondo.form.shipping_method.carrier_code_placeholder', $vars['placeholder']);
        self::assertFalse($vars['label']);
        self::assertFalse($vars['required']);
    }

    /**
     * Validation is not tested here: the functional suite submits the admin form for that
     * (Tests\Setono\SyliusShipmondoPlugin\Functional\Controller\Admin\AdminIntegrationTest).
     * This only pins the constraint the carrier field is configured with.
     *
     * @test
     */
    public function it_requires_a_carrier_in_the_sylius_validation_group_when_pickup_point_delivery_is_enabled(): void
    {
        $carrierCode = $this->createForm(new ShippingMethod())->get('carrierCode');

        self::assertEquals([
            new When(
                expression: 'this.getParent().get("pickupPointDelivery").getData() === true',
                constraints: [
                    new NotBlank(message: 'setono_sylius_shipmondo.shipping_method.carrier_code.not_blank', groups: ['sylius']),
                ],
            ),
        ], $carrierCode->getConfig()->getOption('constraints'));
    }

    /**
     * @return list<FormExtensionInterface>
     */
    protected function getExtensions(): array
    {
        // The shipping method type and its child types are Sylius services; these are their dependencies.
        // There are no calculators, rule checkers or shipping categories, since those fields are not under test.
        $calculatorRegistry = $this->prophesize(ServiceRegistryInterface::class);
        $calculatorRegistry->all()->willReturn([]);

        $ruleCheckerRegistry = $this->prophesize(ServiceRegistryInterface::class);
        $ruleCheckerRegistry->all()->willReturn([]);

        $shippingCategoryRepository = $this->prophesize(RepositoryInterface::class);
        $shippingCategoryRepository->findAll()->willReturn([]);

        $localeProvider = $this->prophesize(TranslationLocaleProviderInterface::class);
        $localeProvider->getDefinedLocalesCodes()->willReturn(['en_US']);
        $localeProvider->getDefaultLocaleCode()->willReturn('en_US');

        return [
            new PreloadedExtension([
                new ShippingMethodType(
                    ShippingMethod::class,
                    ['sylius'],
                    ShippingMethodTranslationType::class,
                    $calculatorRegistry->reveal(),
                    $this->prophesize(FormTypeRegistryInterface::class)->reveal(),
                ),
                new ShippingMethodTranslationType(ShippingMethodTranslation::class, ['sylius']),
                new ResourceTranslationsType($localeProvider->reveal()),
                new ShippingCategoryChoiceType($shippingCategoryRepository->reveal()),
                new CalculatorChoiceType([]),
                new ShippingMethodRuleCollectionType($ruleCheckerRegistry->reveal()),
                new ShippingMethodRuleType(ShippingMethodRule::class, ['sylius'], $this->prophesize(FormTypeRegistryInterface::class)->reveal()),
                new ShippingMethodRuleChoiceType([]),
            ], [
                // Sylius' extension defines the 'button_add_label' option that the shipping method type gives its rules
                CollectionType::class => [new CollectionTypeExtension()],
            ]),
            // The extension sets the 'constraints' option, and Sylius' types set 'validation_groups'
            new ValidatorExtension(Validation::createValidator()),
        ];
    }

    /**
     * @return list<FormTypeExtensionInterface>
     */
    protected function getTypeExtensions(): array
    {
        return [new ShippingMethodTypeExtension()];
    }

    private function createForm(ShippingMethod $shippingMethod): FormInterface
    {
        return $this->factory->create(ShippingMethodType::class, $shippingMethod);
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
     * @param array<array-key, mixed> $vars the view variables of a choice field
     *
     * @return array<string, string> the choices as label => value
     */
    private static function choices(array $vars): array
    {
        self::assertIsArray($vars['choices']);

        $choices = [];
        foreach ($vars['choices'] as $choice) {
            self::assertInstanceOf(ChoiceView::class, $choice);
            self::assertIsString($choice->label);
            self::assertIsString($choice->value);

            $choices[$choice->label] = $choice->value;
        }

        return $choices;
    }
}
