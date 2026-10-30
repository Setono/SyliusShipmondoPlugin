<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Command\RegisterWebhooksCommand;
use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RegisterWebhooksCommandTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_dispatches_a_register_webhooks_command(): void
    {
        $commandBus = $this->prophesize(MessageBusInterface::class);
        $commandBus->dispatch(Argument::type(RegisterWebhooks::class))
            ->willReturn(new Envelope(new RegisterWebhooks()))
            ->shouldBeCalledOnce()
        ;

        $command = new RegisterWebhooksCommand($commandBus->reveal());
        $commandTester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertSame('setono:sylius-shipmondo:register-webhooks', $command->getName());
    }
}
