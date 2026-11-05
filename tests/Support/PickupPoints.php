<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Support;

use Setono\Shipmondo\Response\PickupPoint\PickupPoint;

/**
 * The pickup point a customer chose in the tests: a GLS pickup point in Aarhus
 */
final class PickupPoints
{
    /**
     * The pickup point's JSON, as the pickup point picker submits it and as it is stored on the shipment
     */
    public const JSON = '{"id":"96285","number":"96285","companyName":"GLS","name":"Pakkeshop Netto Vestergade","address":"Vestergade 20","zipcode":"8000","city":"Aarhus C","country":"DK","openingHours":["Man-Fre: 08:00-21:00"],"address2":null}';

    public static function create(string $id = '96285', string $name = 'Pakkeshop Netto Vestergade', ?string $address2 = null): PickupPoint
    {
        return new PickupPoint(
            id: $id,
            number: $id,
            companyName: 'GLS',
            name: $name,
            address: 'Vestergade 20',
            zipcode: '8000',
            city: 'Aarhus C',
            country: 'DK',
            openingHours: ['Man-Fre: 08:00-21:00'],
            address2: $address2,
        );
    }
}
