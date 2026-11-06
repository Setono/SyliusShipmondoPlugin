<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Controller\Shop;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Exception\ShipmondoException;
use Setono\Shipmondo\Request\PickupPointSearch;
use Setono\Shipmondo\Response\PickupPoint\PickupPoint;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
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

final class GetPickupPointsAction
{
    public function __construct(
        private readonly ShippingMethodRepositoryInterface $shippingMethodRepository,
        private readonly CartContextInterface $cartContext,
        private readonly ClientInterface $client,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
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

        if (!$shippingMethod instanceof ShippingMethodInterface || !$shippingMethod->isPickupPointDelivery()) {
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
            $pickupPoints = $this->client->pickupPoints()->search(new PickupPointSearch(
                carrierCode: (string) $shippingMethod->getCarrierCode(),
                countryCode: (string) $shippingAddress->getCountryCode(),
                zipCode: (string) $shippingAddress->getPostcode(),
                address: (string) $shippingAddress->getStreet(),
            ));
        } catch (ShipmondoException $e) {
            // first, since the SDK's InvalidUrlException is an \InvalidArgumentException too
            return $this->logAndAnswerBadGateway(ShipmondoFailure::of($e), $shippingMethodCode);
        } catch (\InvalidArgumentException) {
            // the shipping address lacks what the search needs
            return new JsonResponse(status: Response::HTTP_BAD_REQUEST);
        }

        // The customer might already have chosen a pickup point,
        // so we need to check if the shipment has a chosen pickup point and put that pickup point at the top of the list
        $chosenPickupPoint = self::resolveChosenPickupPoint($order);

        // true sorts after false, so comparing "is b chosen" with "is a chosen" moves the chosen pickup point first
        usort(
            $pickupPoints,
            static fn (PickupPoint $a, PickupPoint $b): int => ($b->id === $chosenPickupPoint) <=> ($a->id === $chosenPickupPoint),
        );

        return new JsonResponse([
            'pickupPoints' => $pickupPoints,
            'html' => $this->twig->render('@SetonoSyliusShipmondoPlugin/shop/ajax/pickup_points.html.twig', [
                'pickupPoints' => $pickupPoints,
            ]),
        ]);
    }

    /**
     * Logs why Shipmondo couldn't search for pickup points, and answers 502 Bad Gateway, on which the shop's pickup point
     * picker tells the customer that the pickup points couldn't be loaded. The answer doesn't say why: that is for the
     * log, not for the shop's visitors
     */
    private function logAndAnswerBadGateway(ShipmondoFailure $failure, string $shippingMethodCode): JsonResponse
    {
        $this->logger->log(
            match ($failure->kind) {
                ShipmondoFailureKind::TemporaryFailure, ShipmondoFailureKind::RateLimited => LogLevel::WARNING,
                default => LogLevel::ERROR,
            },
            'Could not search Shipmondo for pickup points for the shipping method {shippingMethod}, so the customer cannot choose one',
            ['shippingMethod' => $shippingMethodCode] + $failure->logContext(),
        );

        return new JsonResponse(status: Response::HTTP_BAD_GATEWAY);
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

        return $shipment->getShipmondoPickupPoint()?->id;
    }
}
