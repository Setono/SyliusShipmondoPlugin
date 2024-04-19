<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Form\Extension;

use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Sylius\Bundle\ShippingBundle\Form\Type\ShippingMethodType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractTypeExtension<ShippingMethodInterface>
 */
final class ShippingMethodTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('pickupPointDelivery', CheckboxType::class, [
            'label' => 'setono_sylius_shipmondo.form.shipping_method.pickup_point_delivery',
            'required' => false,
        ]);
    }

    public static function getExtendedTypes(): iterable
    {
        yield ShippingMethodType::class;
    }
}
