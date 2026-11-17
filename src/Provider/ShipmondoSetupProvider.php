<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Setono\Shipmondo\Client\Client;
use Setono\Shipmondo\Response\PaymentGateway\PaymentGateway;
use Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The payment gateways and shipment templates in Shipmondo, which the admin maps payment methods and shipping methods
 * to, and the mappings to ones that no longer exist in Shipmondo, e.g. because they were deleted there.
 *
 * It fetches each list once until it is reset. The container resets services tagged kernel.reset between requests, and
 * a Messenger worker after each message, so an upload run on the command line fetches them once, and an UploadOrder
 * command handled by a worker once per order
 */
final class ShipmondoSetupProvider implements ResetInterface
{
    /** @var list<PaymentGateway>|null */
    private ?array $paymentGateways = null;

    /** @var list<ShipmentTemplate>|null */
    private ?array $shipmentTemplates = null;

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @return list<PaymentGateway>
     */
    public function paymentGateways(): array
    {
        if (null === $this->paymentGateways) {
            $paymentGateways = [];
            foreach ($this->client->paymentGateways()->paginate() as $paymentGateway) {
                $paymentGateways[] = $paymentGateway;
            }

            $this->paymentGateways = $paymentGateways;
        }

        return $this->paymentGateways;
    }

    /**
     * @return list<ShipmentTemplate>
     */
    public function shipmentTemplates(): array
    {
        if (null === $this->shipmentTemplates) {
            $shipmentTemplates = [];
            foreach ($this->client->shipmentTemplates()->paginate() as $shipmentTemplate) {
                $shipmentTemplates[] = $shipmentTemplate;
            }

            $this->shipmentTemplates = $shipmentTemplates;
        }

        return $this->shipmentTemplates;
    }

    /**
     * The payment gateway the payment method is mapped to, when it no longer exists in Shipmondo
     */
    public function stalePaymentGateway(PaymentMethodInterface $paymentMethod): ?int
    {
        $paymentGateway = $paymentMethod->getShipmondoId();
        if (null === $paymentGateway) {
            return null;
        }

        foreach ($this->paymentGateways() as $existing) {
            if ($existing->id === $paymentGateway) {
                return null;
            }
        }

        return $paymentGateway;
    }

    /**
     * The shipment templates the shipping method allows that no longer exist in Shipmondo
     *
     * @return list<int>
     */
    public function staleShipmentTemplates(ShippingMethodInterface $shippingMethod): array
    {
        $allowedShipmentTemplates = $shippingMethod->getAllowedShipmentTemplates();
        if ([] === $allowedShipmentTemplates) {
            return [];
        }

        $existing = array_map(static fn (ShipmentTemplate $shipmentTemplate): int => $shipmentTemplate->id, $this->shipmentTemplates());

        return array_values(array_diff($allowedShipmentTemplates, $existing));
    }

    public function reset(): void
    {
        $this->paymentGateways = null;
        $this->shipmentTemplates = null;
    }
}
