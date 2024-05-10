<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Form\Extension;

use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * @extends AbstractTypeExtension<ShipmentInterface>
 */
final class ShipmentTypeExtension extends AbstractTypeExtension
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('shipmondoPickupPoint', HiddenType::class);
        $builder->get('shipmondoPickupPoint')->addModelTransformer(new CallbackTransformer(
            function (?array $pickupPoint): ?string {
                if (null === $pickupPoint) {
                    return null;
                }

                return json_encode($pickupPoint, \JSON_THROW_ON_ERROR);
            },
            function (?string $json): ?array {
                if (null === $json) {
                    return null;
                }

                $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

                if (!is_array($data)) {
                    return null;
                }

                return $data;
            },
        ));
    }

    public static function getExtendedTypes(): iterable
    {
        yield ShipmentType::class;
    }
}
