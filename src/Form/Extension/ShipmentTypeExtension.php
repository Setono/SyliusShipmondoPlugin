<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Form\Extension;

use Setono\Shipmondo\Response\PickupPoint\PickupPoint;
use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\ShipmentType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds the pickup point the customer chooses in the shipping step of the checkout. The pickup point picker
 * (pickup-point-manager.js) submits it as the JSON of a pickup point from the pickup point endpoint.
 *
 * Once the shipping method is known, a shipment whose shipping method has pickup point delivery gets the chosen pickup
 * point, and any other shipment gets none, whatever was submitted. A pickup point that can't be read, or a missing one,
 * is an error at the top of the shipping step: Sylius renders neither this field nor the shipment's errors.
 */
final class ShipmentTypeExtension extends AbstractTypeExtension
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('shipmondoPickupPoint', HiddenType::class, ['mapped' => false])
            ->addEventListener(FormEvents::POST_SUBMIT, $this->choosePickupPoint(...))
        ;
    }

    public static function getExtendedTypes(): iterable
    {
        yield ShipmentType::class;
    }

    private function choosePickupPoint(FormEvent $event): void
    {
        $shipment = $event->getData();
        if (!$shipment instanceof ShipmentInterface) {
            return;
        }

        $shippingMethod = $shipment->getMethod();
        if (!$shippingMethod instanceof ShippingMethodInterface || !$shippingMethod->isPickupPointDelivery()) {
            $shipment->setShipmondoPickupPoint(null);

            return;
        }

        $form = $event->getForm();
        $json = $form->get('shipmondoPickupPoint')->getData();
        if (!is_string($json)) {
            $this->addError($form, 'setono_sylius_shipmondo.checkout.pickup_point.missing');

            return;
        }

        $pickupPoint = self::parse($json);
        if (null === $pickupPoint) {
            $this->addError($form, 'setono_sylius_shipmondo.checkout.pickup_point.invalid');

            return;
        }

        $shipment->setShipmondoPickupPoint($pickupPoint);
    }

    private static function parse(string $json): ?PickupPoint
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

            return is_array($data) ? PickupPoint::fromArray($data) : null;
        } catch (\JsonException|\InvalidArgumentException) {
            return null;
        }
    }

    private function addError(FormInterface $form, string $message): void
    {
        $form->getRoot()->addError(new FormError($this->translator->trans($message, [], 'validators'), $message));
    }
}
