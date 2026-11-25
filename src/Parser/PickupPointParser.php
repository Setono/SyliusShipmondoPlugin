<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Parser;

use Setono\Shipmondo\Response\PickupPoint\PickupPoint;

/**
 * Reads the JSON of a pickup point: the one the pickup point picker submits in checkout, and the one stored on the
 * shipment
 */
final class PickupPointParser
{
    /**
     * Reads a pickup point stored before the Shipmondo SDK spelled the zip code `zipCode` (2.0.0-rc.3) too: its JSON
     * has `zipcode`, which the SDK no longer reads, and every shipment stored with a pickup point until then has it
     *
     * @return PickupPoint|null null if the JSON is not a pickup point's, e.g. invalid JSON or a missing key
     */
    public static function parse(string $json): ?PickupPoint
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                return null;
            }

            $data['zipCode'] ??= $data['zipcode'] ?? null;

            return PickupPoint::fromArray($data);
        } catch (\JsonException|\InvalidArgumentException) {
            return null;
        }
    }
}
