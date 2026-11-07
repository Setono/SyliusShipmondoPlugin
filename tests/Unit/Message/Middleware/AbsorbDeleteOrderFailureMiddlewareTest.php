<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Message\Middleware;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Exception\UnauthorizedException;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\DeleteOrderHandler;
use Setono\SyliusShipmondoPlugin\Message\Middleware\AbsorbDeleteOrderFailureMiddleware;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;

final class AbsorbDeleteOrderFailureMiddlewareTest extends TestCase
{
    use ProphecyTrait;

    private RecordingLogger $logger;

    private \RuntimeException $failure;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->failure = new \RuntimeException('Shipmondo failed');
    }

    /**
     * Handled synchronously, inside the Sylius order cancellation
     *
     * @test
     */
    public function it_absorbs_the_failure_of_a_deletion_that_was_not_received_from_a_transport(): void
    {
        $envelope = $this->handle(new Envelope(new DeleteOrder(42)), transactionNestingLevel: 1);

        // reported as the handler's result, which the canceller reads as "not deleted"
        $handled = $envelope->last(HandledStamp::class);
        self::assertNotNull($handled);
        self::assertFalse($handled->getResult());
        self::assertSame(DeleteOrderHandler::class . '::__invoke', $handled->getHandlerName());
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => 'Could not delete the Shipmondo sales order of the order with the id {orderId}, so the order stays uploaded and linked to it. Delete the sales order in Shipmondo',
            'context' => [
                'orderId' => 42,
                'exception' => $this->failure,
                'shipmondoFailure' => 'unexpected_failure',
                'shipmondoStatusCode' => null,
                'shipmondoError' => null,
            ],
        ]], $this->logger->records);
    }

    /**
     * The handler wraps a failure trying again won't change, and the log tells the Shipmondo failure inside it
     *
     * @test
     */
    public function it_logs_the_shipmondo_failure_inside_an_unrecoverable_one(): void
    {
        $shipmondoFailure = new UnauthorizedException(ShipmondoApi::json(['error' => 'Invalid credentials'], 401));

        $this->handle(new Envelope(new DeleteOrder(42)), 1, new UnrecoverableMessageHandlingException('Not deleted', previous: $shipmondoFailure));

        self::assertSame($shipmondoFailure, $this->logger->records[0]['context']['exception']);
        self::assertSame('credentials_failure', $this->logger->records[0]['context']['shipmondoFailure']);
    }

    /**
     * Only an unrecoverable failure is unwrapped: any other is the Shipmondo failure itself
     *
     * @test
     */
    public function it_logs_a_failure_trying_again_may_fix_as_it_is(): void
    {
        $failure = new \RuntimeException('Shipmondo could not be reached', previous: new \LogicException('The HTTP client failed'));

        $this->handle(new Envelope(new DeleteOrder(42)), 1, $failure);

        self::assertSame($failure, $this->logger->records[0]['context']['exception']);
    }

    /**
     * E.g. the order doesn't exist
     *
     * @test
     */
    public function it_logs_an_unrecoverable_failure_without_a_cause_as_it_is(): void
    {
        $unrecoverable = new UnrecoverableMessageHandlingException('Order with id 42 does not exist');

        $this->handle(new Envelope(new DeleteOrder(42)), 1, $unrecoverable);

        self::assertSame($unrecoverable, $this->logger->records[0]['context']['exception']);
        self::assertSame('unexpected_failure', $this->logger->records[0]['context']['shipmondoFailure']);
    }

    /**
     * E.g. DeleteOrder routed to sync://, inside the Sylius order cancellation's transaction
     *
     * @test
     */
    public function it_absorbs_the_failure_of_a_received_deletion_inside_an_outer_transaction(): void
    {
        $envelope = $this->handle(new Envelope(new DeleteOrder(42), [new ReceivedStamp('sync')]), transactionNestingLevel: 2);

        self::assertFalse($envelope->last(HandledStamp::class)?->getResult());
        self::assertCount(1, $this->logger->records);
    }

    /**
     * A worker: the bus's own transaction is the only one, so failing is safe, and Messenger retries the command
     *
     * @test
     */
    public function it_lets_the_failure_of_a_deletion_a_worker_received_through(): void
    {
        $this->expectException(HandlerFailedException::class);

        try {
            $this->handle(new Envelope(new DeleteOrder(42), [new ReceivedStamp('async')]), transactionNestingLevel: 1);
        } finally {
            self::assertSame([], $this->logger->records);
        }
    }

    /**
     * @test
     */
    public function it_lets_the_failure_of_another_command_through(): void
    {
        $this->expectException(HandlerFailedException::class);

        try {
            $this->handle(new Envelope(new RegisterWebhooks()), transactionNestingLevel: 1);
        } finally {
            self::assertSame([], $this->logger->records);
        }
    }

    /**
     * @test
     */
    public function it_leaves_a_deletion_that_succeeds_alone(): void
    {
        $handled = (new Envelope(new DeleteOrder(42)))->with(new HandledStamp(true, 'handler'));

        $envelope = $this->middleware(transactionNestingLevel: 1)->handle(new Envelope(new DeleteOrder(42)), new StackMiddleware(new class($handled) implements MiddlewareInterface {
            public function __construct(private readonly Envelope $handled)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return $this->handled;
            }
        }));

        self::assertSame($handled, $envelope);
        self::assertSame([], $this->logger->records);
    }

    /**
     * Handles the envelope with a handler that fails, as HandleMessageMiddleware reports it
     */
    private function handle(Envelope $envelope, int $transactionNestingLevel, ?\Throwable $failure = null): Envelope
    {
        $failure ??= $this->failure;

        return $this->middleware($transactionNestingLevel)->handle($envelope, new StackMiddleware(new class($failure) implements MiddlewareInterface {
            public function __construct(private readonly \Throwable $failure)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw new HandlerFailedException($envelope, ['DeleteOrderHandler::__invoke' => $this->failure]);
            }
        }));
    }

    private function middleware(int $transactionNestingLevel): AbsorbDeleteOrderFailureMiddleware
    {
        $connection = $this->prophesize(Connection::class);
        $connection->getTransactionNestingLevel()->willReturn($transactionNestingLevel);

        return new AbsorbDeleteOrderFailureMiddleware($connection->reveal(), $this->logger);
    }
}
