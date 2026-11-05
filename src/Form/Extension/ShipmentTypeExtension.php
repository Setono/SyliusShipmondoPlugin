<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Form\Extension;

use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Setono\SyliusShipmondoPlugin\Parser\PickupPointParser;
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
    /**
     * The error when the shipping method has pickup point delivery and no pickup point was chosen. A translation key in
     * the validators domain
     */
    public const ERROR_MISSING_PICKUP_POINT = 'setono_sylius_shipmondo.checkout.pickup_point.missing';

    /**
     * The error when the chosen pickup point can't be read. A translation key in the validators domain
     */
    public const ERROR_INVALID_PICKUP_POINT = 'setono_sylius_shipmondo.checkout.pickup_point.invalid';

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

        $form = $event->getForm();

        // Sylius reports a shipping method it can't read, and the shipment still has its previous one
        if (!$form->get('method')->isSynchronized()) {
            return;
        }

        $shippingMethod = $shipment->getMethod();
        if (!$shippingMethod instanceof ShippingMethodInterface || !$shippingMethod->isPickupPointDelivery()) {
            $shipment->setShipmondoPickupPoint(null);

            return;
        }

        $json = $form->get('shipmondoPickupPoint')->getData();
        if (!is_string($json)) {
            $this->addError($form, self::ERROR_MISSING_PICKUP_POINT);

            return;
        }

        $pickupPoint = PickupPointParser::parse($json);
        if (null === $pickupPoint) {
            $this->addError($form, self::ERROR_INVALID_PICKUP_POINT);

            return;
        }

        $shipment->setShipmondoPickupPoint($pickupPoint);
    }

    private function addError(FormInterface $form, string $message): void
    {
        $form->getRoot()->addError(new FormError($this->translator->trans($message, [], 'validators'), $message));
    }
}
