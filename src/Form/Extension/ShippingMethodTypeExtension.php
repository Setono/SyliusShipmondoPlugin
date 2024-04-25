<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Form\Extension;

use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @extends AbstractTypeExtension<ShippingMethodInterface>
 */
final class ShippingMethodTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // The carrier codes has been copied from here: https://app.shipmondo.com/api/public/v3/specification#/operations/pickup_points_get
        $carriers = [
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

        $builder
            ->add('pickupPointDelivery', CheckboxType::class, [
                'label' => 'setono_sylius_shipmondo.form.shipping_method.pickup_point_delivery',
                'required' => false,
            ])
            ->add('carrierCode', ChoiceType::class, [
                'choices' => $carriers,
                'label' => false,
                'placeholder' => 'setono_sylius_shipmondo.form.shipping_method.carrier_code_placeholder',
                'required' => false,
                'constraints' => [
                    new Assert\When([
                        'expression' => 'this.getParent().get("pickupPointDelivery").getData() === true',
                        'constraints' => [
                            new Assert\NotBlank(
                                message: 'setono_sylius_shipmondo.shipping_method.carrier_code.not_blank',
                                groups: ['sylius'],
                            ),
                        ],
                    ]),
                ],
            ])
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        yield ShippingMethodType::class;
    }
}
