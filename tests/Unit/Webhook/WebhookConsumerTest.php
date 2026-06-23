<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\RemoteEventHandlerInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\WebhookConsumer;
use Symfony\Component\RemoteEvent\RemoteEvent as BaseRemoteEvent;

final class WebhookConsumerTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_dispatches_the_event_to_the_handler(): void
    {
        $event = new RemoteEvent('shipmondo.event', [], WebhookResourceName::Orders, WebhookAction::StatusUpdate);

        $handler = $this->prophesize(RemoteEventHandlerInterface::class);
        $handler->handle($event)->shouldBeCalled();

        (new WebhookConsumer($handler->reveal()))->consume($event);
    }

    /**
     * @test
     */
    public function it_rejects_a_remote_event_that_is_not_a_shipmondo_event(): void
    {
        $handler = $this->prophesize(RemoteEventHandlerInterface::class);
        $handler->handle(Argument::any())->shouldNotBeCalled();

        $this->expectException(\InvalidArgumentException::class);

        (new WebhookConsumer($handler->reveal()))->consume(new BaseRemoteEvent('name', 'id', []));
    }
}
