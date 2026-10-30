<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Model;

use PHPUnit\Framework\TestCase;
use Setono\Shipmondo\Response\ShipmentTemplate\Receiver;
use Setono\Shipmondo\Response\ShipmentTemplate\Sender;
use Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\ShippingMethod;

final class ShippingMethodTraitTest extends TestCase
{
    /**
     * @test
     */
    public function it_has_sensible_defaults(): void
    {
        $shippingMethod = new ShippingMethod();

        self::assertFalse($shippingMethod->isPickupPointDelivery());
        self::assertNull($shippingMethod->getCarrierCode());
        self::assertSame([], $shippingMethod->getAllowedShipmentTemplates());
        self::assertFalse($shippingMethod->hasAllowedShipmentTemplate(1));
    }

    /**
     * @test
     */
    public function it_keeps_the_carrier_code_while_pickup_point_delivery_is_enabled(): void
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setPickupPointDelivery(true);
        $shippingMethod->setCarrierCode('gls');

        self::assertTrue($shippingMethod->isPickupPointDelivery());
        self::assertSame('gls', $shippingMethod->getCarrierCode());

        $shippingMethod->setPickupPointDelivery(true);
        self::assertSame('gls', $shippingMethod->getCarrierCode());
    }

    /**
     * @test
     */
    public function it_clears_the_carrier_code_when_pickup_point_delivery_is_disabled(): void
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setPickupPointDelivery(true);
        $shippingMethod->setCarrierCode('gls');

        $shippingMethod->setPickupPointDelivery(false);

        self::assertFalse($shippingMethod->isPickupPointDelivery());
        self::assertNull($shippingMethod->getCarrierCode());
    }

    /**
     * @test
     */
    public function it_replaces_the_allowed_shipment_templates_with_ids_or_templates(): void
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setAllowedShipmentTemplates([1, 2]);

        // the admin form posts the ids as strings
        $shippingMethod->setAllowedShipmentTemplates(['664116', self::shipmentTemplate(664115), 664114, '664116']);

        self::assertSame([664116, 664115, 664114], $shippingMethod->getAllowedShipmentTemplates());
        self::assertTrue($shippingMethod->hasAllowedShipmentTemplate(664115));
        self::assertTrue($shippingMethod->hasAllowedShipmentTemplate(self::shipmentTemplate(664114)));
        self::assertFalse($shippingMethod->hasAllowedShipmentTemplate(1));
        self::assertFalse($shippingMethod->hasAllowedShipmentTemplate(self::shipmentTemplate(2)));
    }

    /**
     * @test
     */
    public function it_clears_the_allowed_shipment_templates(): void
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setAllowedShipmentTemplates([1, 2]);

        $shippingMethod->setAllowedShipmentTemplates(null);

        self::assertSame([], $shippingMethod->getAllowedShipmentTemplates());
        self::assertFalse($shippingMethod->hasAllowedShipmentTemplate(1));
    }

    /**
     * @test
     */
    public function it_adds_an_allowed_shipment_template_once(): void
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setAllowedShipmentTemplates(null);

        $shippingMethod->addAllowedShipmentTemplate(self::shipmentTemplate(5));
        $shippingMethod->addAllowedShipmentTemplate(5);
        $shippingMethod->addAllowedShipmentTemplate(6);

        self::assertSame([5, 6], $shippingMethod->getAllowedShipmentTemplates());
    }

    private static function shipmentTemplate(int $id): ShipmentTemplate
    {
        return new ShipmentTemplate($id, 'Template', new Sender('DK'), new Receiver('DK'));
    }
}
