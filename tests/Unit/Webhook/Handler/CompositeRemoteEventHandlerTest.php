<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\CompositeRemoteEventHandler;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\RemoteEventHandlerInterface;

final class CompositeRemoteEventHandlerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_delegates_to_every_added_handler(): void
    {
        $event = new RemoteEvent('shipmondo.event', [], 'orders', 'status_update');

        $first = $this->prophesize(RemoteEventHandlerInterface::class);
        $first->handle($event)->shouldBeCalled();

        $second = $this->prophesize(RemoteEventHandlerInterface::class);
        $second->handle($event)->shouldBeCalled();

        $composite = new CompositeRemoteEventHandler();
        $composite->add($first->reveal());
        $composite->add($second->reveal());

        $composite->handle($event);
    }
}
