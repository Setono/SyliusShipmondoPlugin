<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Controller\Shop;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Request\PickupPoints\PickupPointsCollectionQuery;
use Setono\Shipmondo\Response\PickupPoints\PickupPoint;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Webmozart\Assert\Assert;

final class GetPickupPointsAction
{
    public function __construct(
        private readonly ShippingMethodRepositoryInterface $shippingMethodRepository,
        private readonly CartContextInterface $cartContext,
        private readonly ClientInterface $client,
        private readonly Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $shippingMethodCode = $request->query->getString('shippingMethod');

        if ('' === $shippingMethodCode) {
            return new JsonResponse(status: Response::HTTP_BAD_REQUEST);
        }

        $shippingMethod = $this->shippingMethodRepository->findOneBy([
            'code' => $shippingMethodCode,
            'enabled' => true,
        ]);

        if (!$shippingMethod instanceof ShippingMethodInterface) {
            return new JsonResponse(status: Response::HTTP_BAD_REQUEST);
        }

        try {
            $order = $this->cartContext->getCart();
        } catch (CartNotFoundException) {
            return new JsonResponse(status: Response::HTTP_BAD_REQUEST);
        }

        if (!$order instanceof OrderInterface) {
            return new JsonResponse(status: Response::HTTP_BAD_REQUEST);
        }

        $shippingAddress = $order->getShippingAddress();
        if (null === $shippingAddress) {
            return new JsonResponse(status: Response::HTTP_BAD_REQUEST);
        }

        try {
            $pickupPoints = $this->client->pickupPoints()->get(new PickupPointsCollectionQuery(
                carrierCode: (string) $shippingMethod->getCarrierCode(),
                countryCode: (string) $shippingAddress->getCountryCode(),
                zipCode: (string) $shippingAddress->getPostcode(),
                address: (string) $shippingAddress->getStreet(),
            ))->items;
        } catch (\InvalidArgumentException) {
            return new JsonResponse(status: Response::HTTP_BAD_REQUEST);
        }

        // The customer might already have chosen a pickup point,
        // so we need to check if the shipment has a chosen pickup point and put that pickup point at the top of the list
        $chosenPickupPoint = self::resolveChosenPickupPoint($order);

        usort($pickupPoints, static function (PickupPoint $a, PickupPoint $b) use ($chosenPickupPoint) {
            if ($a->id === $chosenPickupPoint) {
                return -1;
            }

            if ($b->id === $chosenPickupPoint) {
                return 1;
            }

            return 0;
        });

        return new JsonResponse([
            'pickupPoints' => $pickupPoints,
            'html' => $this->twig->render('@SetonoSyliusShipmondoPlugin/shop/ajax/pickup_points.html.twig', [
                'pickupPoints' => $pickupPoints,
            ]),
        ]);
    }

    /**
     * Will return the id of the chosen pickup point if the shipment has one
     *
     * todo this only works if the order has _one_ shipment
     */
    private static function resolveChosenPickupPoint(OrderInterface $order): ?string
    {
        $shipment = $order->getShipments()->first();
        if (!$shipment instanceof ShipmentInterface) {
            return null;
        }

        $pickupPoint = $shipment->getShipmondoPickupPoint();
        if (null === $pickupPoint) {
            return null;
        }

        $id = $pickupPoint['id'] ?? null;
        Assert::nullOrString($id);

        return $id;
    }
}
