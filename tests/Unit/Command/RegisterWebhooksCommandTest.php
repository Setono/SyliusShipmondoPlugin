<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Exception\UnauthorizedException;
use Setono\SyliusShipmondoPlugin\Command\RegisterWebhooksCommand;
use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Translations;

final class RegisterWebhooksCommandTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $commandBus;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->commandBus = $this->prophesize(MessageBusInterface::class);
        $this->logger = new RecordingLogger();
    }

    /**
     * @test
     */
    public function it_dispatches_a_register_webhooks_command(): void
    {
        $this->commandBus->dispatch(Argument::type(RegisterWebhooks::class))
            ->willReturn(new Envelope(new RegisterWebhooks()))
            ->shouldBeCalledOnce()
        ;

        $command = $this->command();
        $commandTester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertSame('', $commandTester->getDisplay());
        self::assertSame([], $this->logger->records);
        self::assertSame('setono:sylius-shipmondo:register-webhooks', $command->getName());
    }

    /**
     * @test
     */
    public function it_fails_and_explains_why_when_shipmondo_fails(): void
    {
        $exception = new UnauthorizedException(ShipmondoApi::json(['error' => 'Invalid credentials'], 401));
        $this->commandBus->dispatch(Argument::type(RegisterWebhooks::class))
            ->willThrow(new HandlerFailedException(new Envelope(new RegisterWebhooks()), ['RegisterWebhooksHandler::__invoke' => $exception]))
        ;

        $commandTester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $commandTester->execute([]));
        self::assertStringContainsString(
            '[ERROR] The webhooks could not be registered, so Shipmondo may send only some of them, or none. Register them again once the problem is solved. Shipmondo did not accept the API credentials.',
            (string) preg_replace('/\s+/', ' ', $commandTester->getDisplay()),
        );
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => 'The webhooks could not be registered in Shipmondo',
            'context' => [
                'exception' => $exception,
                'shipmondoFailure' => 'credentials_failure',
                'shipmondoStatusCode' => 401,
                'shipmondoError' => 'Invalid credentials',
            ],
        ]], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_lets_a_failure_that_is_not_shipmondos_through(): void
    {
        $failure = new HandlerFailedException(new Envelope(new RegisterWebhooks()), ['RegisterWebhooksHandler::__invoke' => new \LogicException('A bug')]);
        $this->commandBus->dispatch(Argument::type(RegisterWebhooks::class))->willThrow($failure);

        try {
            (new CommandTester($this->command()))->execute([]);
            self::fail('The failure was not let through');
        } catch (HandlerFailedException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], $this->logger->records);
    }

    private function command(): RegisterWebhooksCommand
    {
        return new RegisterWebhooksCommand($this->commandBus->reveal(), Translations::translator(), $this->logger);
    }
}
