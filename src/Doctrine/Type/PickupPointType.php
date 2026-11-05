<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use Setono\Shipmondo\Response\PickupPoint\PickupPoint;
use Setono\SyliusShipmondoPlugin\Parser\PickupPointParser;

/**
 * Stores the pickup point a customer chose as its JSON in a JSON column. The bundle registers it with Doctrine.
 *
 * A stored value it can't read as a pickup point reads as no pickup point, so the shipment and its order still load.
 * Only a tampered checkout request could have stored one, before the checkout parsed pickup points.
 */
final class PickupPointType extends JsonType
{
    public const NAME = 'setono_sylius_shipmondo_pickup_point';

    /**
     * @param mixed $value
     *
     * @throws ConversionException if the value is neither null nor a pickup point
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null !== $value && !$value instanceof PickupPoint) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', PickupPoint::class]);
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    /**
     * @param mixed $value
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): ?PickupPoint
    {
        // some drivers read a JSON column as a stream
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) ? PickupPointParser::parse($value) : null;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * The comment names the type, so the schema tool tells the column from a plain JSON column instead of changing
     * it on every run (DBAL 2, and DBAL 3 on platforms without inline column comments)
     */
    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
