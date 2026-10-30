<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Model;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;

final class RegisteredWebhooksTest extends TestCase
{
    /**
     * @test
     */
    public function it_is_empty_when_created(): void
    {
        $registeredWebhooks = new RegisteredWebhooks();

        self::assertNull($registeredWebhooks->getId());
        self::assertNull($registeredWebhooks->getVersion());
        self::assertNull($registeredWebhooks->getRegisteredAt());
    }

    /**
     * @test
     */
    public function it_holds_the_registered_version_and_time(): void
    {
        $registeredAt = new \DateTimeImmutable('2024-05-01 12:00:00');

        $registeredWebhooks = new RegisteredWebhooks();
        $registeredWebhooks->setVersion('49fb4dab33cd8cf27e6a9d1944dcbbdb');
        $registeredWebhooks->setRegisteredAt($registeredAt);

        self::assertSame('49fb4dab33cd8cf27e6a9d1944dcbbdb', $registeredWebhooks->getVersion());
        self::assertSame($registeredAt, $registeredWebhooks->getRegisteredAt());
    }
}
