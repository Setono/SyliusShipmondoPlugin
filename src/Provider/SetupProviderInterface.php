<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Provider;

use Setono\Shipmondo\Exception\ShipmondoException;
use Setono\Shipmondo\Response\PaymentGateway\PaymentGateway;
use Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;

/**
 * The payment gateways and shipment templates in Shipmondo, which the admin maps payment methods and shipping methods
 * to, and the stale mappings: the ones to payment gateways and shipment templates that no longer exist in Shipmondo,
 * e.g. because they were deleted there
 */
interface SetupProviderInterface
{
    /**
     * @return list<PaymentGateway>
     *
     * @throws ShipmondoException when Shipmondo fails
     */
    public function paymentGateways(): array;

    /**
     * @return list<ShipmentTemplate>
     *
     * @throws ShipmondoException when Shipmondo fails
     */
    public function shipmentTemplates(): array;

    /**
     * The payment gateway the payment method is mapped to, when it no longer exists in Shipmondo
     *
     * @throws ShipmondoException when Shipmondo fails
     */
    public function stalePaymentGateway(PaymentMethodInterface $paymentMethod): ?int;

    /**
     * The shipment templates the shipping method allows that no longer exist in Shipmondo
     *
     * @return list<int>
     *
     * @throws ShipmondoException when Shipmondo fails
     */
    public function staleShipmentTemplates(ShippingMethodInterface $shippingMethod): array;
}
