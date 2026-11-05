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
     * @return PickupPoint|null null if the JSON is not a pickup point's, e.g. invalid JSON or a missing key
     */
    public static function parse(string $json): ?PickupPoint
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

            return is_array($data) ? PickupPoint::fromArray($data) : null;
        } catch (\JsonException|\InvalidArgumentException) {
            return null;
        }
    }
}
