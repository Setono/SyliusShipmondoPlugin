<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use Setono\SyliusShipmondoPlugin\Failure\RecordedShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;

/**
 * Stores the Shipmondo failure that put an order in its Shipmondo state as JSON in a JSON column: its kind, status code
 * and message. The bundle registers it with Doctrine.
 *
 * A stored value it can't read as a Shipmondo failure, e.g. one of a kind this version of the plugin doesn't know,
 * reads as no failure, so the order still loads
 */
final class RecordedShipmondoFailureType extends JsonType
{
    public const NAME = 'setono_sylius_shipmondo_recorded_shipmondo_failure';

    /**
     * @param mixed $value
     *
     * @throws ConversionException if the value is neither null nor a Shipmondo failure
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!$value instanceof RecordedShipmondoFailure) {
            throw ConversionException::conversionFailedInvalidType($value, self::NAME, ['null', RecordedShipmondoFailure::class]);
        }

        return parent::convertToDatabaseValue([
            'kind' => $value->kind->value,
            'statusCode' => $value->statusCode,
            'message' => $value->message,
        ], $platform);
    }

    /**
     * @param mixed $value
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): ?RecordedShipmondoFailure
    {
        // some drivers read a JSON column as a stream
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        $data = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($data) || !is_string($data['kind'] ?? null)) {
            return null;
        }

        $kind = ShipmondoFailureKind::tryFrom($data['kind']);
        if (null === $kind) {
            return null;
        }

        $statusCode = $data['statusCode'] ?? null;
        $message = $data['message'] ?? null;

        return new RecordedShipmondoFailure($kind, is_int($statusCode) ? $statusCode : null, is_string($message) ? $message : '');
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
