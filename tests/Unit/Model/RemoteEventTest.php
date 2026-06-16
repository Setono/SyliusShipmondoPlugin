<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Model;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Model\RemoteEvent;

final class RemoteEventTest extends TestCase
{
    /**
     * @test
     */
    public function it_initializes_with_defaults(): void
    {
        $remoteEvent = new RemoteEvent();

        self::assertNull($remoteEvent->getId());
        self::assertSame([], $remoteEvent->getPayload());
        self::assertInstanceOf(\DateTimeImmutable::class, $remoteEvent->getCreatedAt());
    }

    /**
     * @test
     */
    public function it_sets_and_gets_the_resource(): void
    {
        $remoteEvent = new RemoteEvent();
        $remoteEvent->setResource('order');

        self::assertSame('order', $remoteEvent->getResource());
    }

    /**
     * @test
     */
    public function it_sets_and_gets_the_action(): void
    {
        $remoteEvent = new RemoteEvent();
        $remoteEvent->setAction('create');

        self::assertSame('create', $remoteEvent->getAction());
    }

    /**
     * @test
     */
    public function it_sets_and_gets_the_payload(): void
    {
        $remoteEvent = new RemoteEvent();
        $remoteEvent->setPayload(['id' => 123, 'status' => 'shipped']);

        self::assertSame(['id' => 123, 'status' => 'shipped'], $remoteEvent->getPayload());
    }
}
