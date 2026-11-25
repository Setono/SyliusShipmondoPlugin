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
        self::assertNull($registeredWebhooks->getEndpoint());
        self::assertNull($registeredWebhooks->getRegisteredAt());
    }

    /**
     * @test
     */
    public function it_holds_the_endpoint_and_the_time_of_the_registration(): void
    {
        $registeredAt = new \DateTimeImmutable('2024-05-01 12:00:00');

        $registeredWebhooks = new RegisteredWebhooks();
        $registeredWebhooks->setEndpoint('https://shop.example.com/webhook/shipmondo');
        $registeredWebhooks->setRegisteredAt($registeredAt);

        self::assertSame('https://shop.example.com/webhook/shipmondo', $registeredWebhooks->getEndpoint());
        self::assertSame($registeredAt, $registeredWebhooks->getRegisteredAt());
    }
}
