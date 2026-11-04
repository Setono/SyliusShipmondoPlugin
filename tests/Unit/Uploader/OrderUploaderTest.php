<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Uploader;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Exception\InternalServerErrorException;
use Setono\Shipmondo\Exception\MalformedResponseException;
use Setono\Shipmondo\Exception\NotFoundException;
use Setono\Shipmondo\Exception\TooManyRequestsException;
use Setono\Shipmondo\Exception\UnauthorizedException;
use Setono\Shipmondo\Exception\UnexpectedStatusCodeException;
use Setono\Shipmondo\Exception\ValidationException;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\UploadOrderHandler;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProviderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploader;
use Setono\SyliusShipmondoPlugin\Uploader\UploadOutcome;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Unit\NetworkException;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Webmozart\Assert\Assert;

/**
 * Exercises the uploader through its interface with the real order state machine, the real Shipmondo SDK over a
 * scripted HTTP transport, and a real command bus that handles UploadOrder synchronously with the real handler.
 */
final class OrderUploaderTest extends TestCase
{
    use ProphecyTrait;

    private const PENDING = OrderInterface::SHIPMONDO_STATE_PENDING;

    private const UPLOADING = OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO;

    private const UPLOADED = OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO;

    private const FAILED = OrderInterface::SHIPMONDO_STATE_FAILED;

    private const REJECTED_MESSAGE = 'Shipmondo rejected the upload of the order {order}, so the order is failed';

    private const CREDENTIALS_FAILURE_MESSAGE = 'Shipmondo did not accept the API credentials when uploading the order {order}, so the order goes back to pending';

    private const RATE_LIMITED_MESSAGE = 'Shipmondo rate limited the upload of the order {order}, so the order goes back to pending';

    private const TEMPORARY_FAILURE_MESSAGE = 'The upload of the order {order} failed temporarily, so the order goes back to pending';

    private const ERROR_MESSAGE = 'The upload of the order {order} failed unexpectedly, so the order is failed';

    private ScriptedHttpClient $shipmondo;

    private RecordingLogger $logger;

    /** @var ObjectProphecy<SalesOrderDataMapperInterface> */
    private ObjectProphecy $salesOrderDataMapper;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    /**
     * The orders the upload run finds pending and qualifying for upload, in this order
     *
     * @var list<Order>
     */
    private array $orders = [];

    /**
     * The command bus the upload run dispatches UploadOrder on. By default, a bus that handles it synchronously
     */
    private ?MessageBusInterface $commandBus = null;

    protected function setUp(): void
    {
        $this->shipmondo = new ScriptedHttpClient();
        $this->logger = new RecordingLogger();

        // the mapper puts the order number on the sales order, so a test can tell which order a request uploads
        $this->salesOrderDataMapper = $this->prophesize(SalesOrderDataMapperInterface::class);
        $this->salesOrderDataMapper->map(Argument::cetera())->will(static function (array $args): void {
            [$order, $salesOrder] = $args;
            Assert::isInstanceOf($order, OrderInterface::class);
            Assert::isInstanceOf($salesOrder, SalesOrderRequest::class);

            $salesOrder->orderId = $order->getNumber();
        });

        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->will(static function (array $args): mixed {
            Assert::isCallable($args[0]);

            return $args[0]();
        });
        $this->entityManager->refresh(Argument::cetera())->will(static function (): void {
        });
        $this->entityManager->flush()->will(static function (): void {
        });
    }

    /**
     * @test
     */
    public function it_uploads_an_order_and_links_it_to_its_sales_order(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $this->shipmondo->respond(201, ['id' => 1001]);
        $this->entityManager->flush()->shouldBeCalledOnce();

        self::assertSame(UploadOutcome::Uploaded, $this->uploader()->uploadOrder($order));

        self::assertSame(self::UPLOADED, $order->getShipmondoState());
        self::assertSame(1001, $order->getShipmondoId());
        self::assertSame(['#001'], $this->uploadedOrderNumbers());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'The order {order} was uploaded to Shipmondo as the sales order {salesOrder}',
            'context' => ['order' => '#001', 'outcome' => 'uploaded', 'state' => self::UPLOADED, 'salesOrder' => 1001],
        ]], $this->logRecords());
    }

    /**
     * @test
     *
     * @dataProvider failedUploads
     */
    public function it_settles_the_order_by_how_shipmondo_answered_a_failed_upload(
        int $statusCode,
        string $exception,
        UploadOutcome $outcome,
        string $state,
        string $level,
        string $message,
    ): void {
        $order = $this->order(1, self::UPLOADING);
        $this->shipmondo->respond($statusCode, ['error' => 'The payment gateway does not exist']);
        $this->entityManager->flush()->shouldBeCalledOnce();

        self::assertSame($outcome, $this->uploader()->uploadOrder($order));

        self::assertSame($state, $order->getShipmondoState());
        self::assertNull($order->getShipmondoId());
        self::assertSame([[
            'level' => $level,
            'message' => $message,
            'context' => [
                'order' => '#001',
                'outcome' => $outcome->value,
                'state' => $state,
                'salesOrder' => null,
                'exception' => $exception,
                'shipmondoStatusCode' => $statusCode,
                'shipmondoError' => 'The payment gateway does not exist',
            ],
        ]], $this->logRecords());
    }

    /**
     * @return iterable<string, array{int, string, UploadOutcome, string, string, string}>
     */
    public static function failedUploads(): iterable
    {
        $rejected = static fn (int $statusCode, string $exception): array => [
            $statusCode, $exception, UploadOutcome::Rejected, self::FAILED, LogLevel::ERROR, self::REJECTED_MESSAGE,
        ];

        yield '400 Bad Request' => $rejected(400, UnexpectedStatusCodeException::class);
        yield '401 Unauthorized' => [401, UnauthorizedException::class, UploadOutcome::CredentialsFailure, self::PENDING, LogLevel::ERROR, self::CREDENTIALS_FAILURE_MESSAGE];
        yield '402 Payment Required' => $rejected(402, UnexpectedStatusCodeException::class);
        yield '403 Forbidden' => [403, UnexpectedStatusCodeException::class, UploadOutcome::CredentialsFailure, self::PENDING, LogLevel::ERROR, self::CREDENTIALS_FAILURE_MESSAGE];
        yield '404 Not Found' => $rejected(404, NotFoundException::class);
        yield '422 Unprocessable Entity' => $rejected(422, ValidationException::class);
        yield '428 Precondition Required' => $rejected(428, UnexpectedStatusCodeException::class);
        yield '429 Too Many Requests' => [429, TooManyRequestsException::class, UploadOutcome::RateLimited, self::PENDING, LogLevel::WARNING, self::RATE_LIMITED_MESSAGE];
        yield '430' => $rejected(430, UnexpectedStatusCodeException::class);
        yield '499' => $rejected(499, UnexpectedStatusCodeException::class);
        yield '500 Internal Server Error' => [500, InternalServerErrorException::class, UploadOutcome::TemporaryFailure, self::PENDING, LogLevel::WARNING, self::TEMPORARY_FAILURE_MESSAGE];
        yield '503 Service Unavailable' => [503, InternalServerErrorException::class, UploadOutcome::TemporaryFailure, self::PENDING, LogLevel::WARNING, self::TEMPORARY_FAILURE_MESSAGE];
    }

    /**
     * @test
     */
    public function it_sends_the_order_back_to_pending_when_shipmondo_cannot_be_reached(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $this->shipmondo->failToConnect();

        self::assertSame(UploadOutcome::TemporaryFailure, $this->uploader()->uploadOrder($order));

        self::assertSame(self::PENDING, $order->getShipmondoState());
        self::assertSame([[
            'level' => LogLevel::WARNING,
            'message' => self::TEMPORARY_FAILURE_MESSAGE,
            'context' => [
                'order' => '#001',
                'outcome' => 'temporary_failure',
                'state' => self::PENDING,
                'salesOrder' => null,
                'exception' => NetworkException::class,
            ],
        ]], $this->logRecords());
    }

    /**
     * @test
     */
    public function it_fails_the_order_when_it_cannot_be_turned_into_a_sales_order(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $error = new \DivisionByZeroError('Division by zero');
        $this->salesOrderDataMapper->map($order, Argument::any())->willThrow($error);
        $this->entityManager->flush()->shouldBeCalledOnce();

        self::assertSame(UploadOutcome::UnexpectedFailure, $this->uploader()->uploadOrder($order));

        self::assertSame(self::FAILED, $order->getShipmondoState());
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => self::ERROR_MESSAGE,
            'context' => ['order' => '#001', 'outcome' => 'unexpected_failure', 'state' => self::FAILED, 'salesOrder' => null, 'exception' => $error],
        ]], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_fails_the_order_when_the_sdk_cannot_read_shipmondos_answer_since_the_sales_order_may_exist(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $this->shipmondo->respond(201, body: 'not JSON');

        self::assertSame(UploadOutcome::UnexpectedFailure, $this->uploader()->uploadOrder($order));

        self::assertSame(self::FAILED, $order->getShipmondoState());
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => self::ERROR_MESSAGE,
            'context' => [
                'order' => '#001',
                'outcome' => 'unexpected_failure',
                'state' => self::FAILED,
                'salesOrder' => null,
                'exception' => MalformedResponseException::class,
                'shipmondoStatusCode' => 201,
                'shipmondoError' => null,
            ],
        ]], $this->logRecords());
    }

    /**
     * @test
     */
    public function it_sends_the_order_back_to_pending_when_it_changed_after_it_was_queued_for_upload(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $order->setVersion(5);
        $this->entityManager->flush()->shouldBeCalledOnce();

        self::assertSame(UploadOutcome::ChangedSinceQueued, $this->uploader()->uploadOrder($order, 4));

        self::assertSame(self::PENDING, $order->getShipmondoState());
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'The order {order} changed after it was queued for upload, so it goes back to pending',
            'context' => ['order' => '#001', 'outcome' => 'changed_since_queued', 'state' => self::PENDING, 'salesOrder' => null],
        ]], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_uploads_an_order_that_is_unchanged_since_it_was_queued_for_upload(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $order->setVersion(5);
        $this->shipmondo->respond(201, ['id' => 1001]);

        self::assertSame(UploadOutcome::Uploaded, $this->uploader()->uploadOrder($order, 5));

        self::assertSame(1001, $order->getShipmondoId());
    }

    /**
     * @test
     *
     * @dataProvider statesNotWaitingForAnUpload
     */
    public function it_leaves_an_order_alone_that_is_not_waiting_for_an_upload(string $state): void
    {
        $order = $this->order(1, $state);
        $this->entityManager->flush()->shouldNotBeCalled();

        self::assertSame(UploadOutcome::Skipped, $this->uploader()->uploadOrder($order));

        self::assertSame($state, $order->getShipmondoState());
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'The order {order} was not uploaded, because its Shipmondo state is {state}, not uploading',
            'context' => ['order' => '#001', 'outcome' => 'skipped', 'state' => $state, 'salesOrder' => null],
        ]], $this->logger->records);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesNotWaitingForAnUpload(): iterable
    {
        yield 'pending' => [self::PENDING];
        yield 'uploaded' => [self::UPLOADED];
        yield 'failed' => [self::FAILED];
    }

    /**
     * @test
     */
    public function it_claims_and_uploads_every_order_that_qualifies_for_upload(): void
    {
        $first = $this->order(1);
        $second = $this->order(2);
        $this->shipmondo->respond(201, ['id' => 1001])->respond(201, ['id' => 1002]);

        // each order's row is locked and read again before it is claimed
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledTimes(2);
        $this->entityManager->refresh($first, LockMode::PESSIMISTIC_WRITE)->shouldBeCalledOnce();
        $this->entityManager->refresh($second, LockMode::PESSIMISTIC_WRITE)->shouldBeCalledOnce();

        self::assertNull($this->uploader()->upload());

        self::assertSame(['#001', '#002'], $this->uploadedOrderNumbers());
        self::assertSame([self::UPLOADED, 1001], [$first->getShipmondoState(), $first->getShipmondoId()]);
        self::assertSame([self::UPLOADED, 1002], [$second->getShipmondoState(), $second->getShipmondoId()]);
    }

    /**
     * @test
     */
    public function it_skips_an_order_that_is_no_longer_pending_when_it_is_claimed(): void
    {
        $claimedElsewhere = $this->order(1);
        $pending = $this->order(2);
        $this->shipmondo->respond(201, ['id' => 1002]);

        // reading the order again reveals that another upload run claimed it after this run loaded it
        $this->entityManager->refresh($claimedElsewhere, LockMode::PESSIMISTIC_WRITE)->will(static function () use ($claimedElsewhere): void {
            $claimedElsewhere->setShipmondoState(self::UPLOADING);
        });

        self::assertNull($this->uploader()->upload());

        self::assertSame(['#002'], $this->uploadedOrderNumbers());
        self::assertSame(self::UPLOADING, $claimedElsewhere->getShipmondoState());
        self::assertSame(self::UPLOADED, $pending->getShipmondoState());
    }

    /**
     * @test
     */
    public function it_keeps_uploading_the_other_orders_after_an_upload_fails_in_a_way_that_says_nothing_about_them(): void
    {
        $rejected = $this->order(1);
        $serverError = $this->order(2);
        $unmappable = $this->order(3);
        $unreachable = $this->order(4);
        $uploaded = $this->order(5);

        $this->salesOrderDataMapper->map($unmappable, Argument::any())->willThrow(new \RuntimeException('The order has no shipping address'));
        $this->shipmondo
            ->respond(422, ['error' => 'Invalid data'])
            ->respond(500)
            ->failToConnect()
            ->respond(201, ['id' => 1005])
        ;

        self::assertNull($this->uploader()->upload());

        self::assertSame(['#001', '#002', '#004', '#005'], $this->uploadedOrderNumbers());
        self::assertSame(self::FAILED, $rejected->getShipmondoState());
        self::assertSame(self::PENDING, $serverError->getShipmondoState());
        self::assertSame(self::FAILED, $unmappable->getShipmondoState());
        self::assertSame(self::PENDING, $unreachable->getShipmondoState());
        self::assertSame(self::UPLOADED, $uploaded->getShipmondoState());
    }

    /**
     * @test
     *
     * @dataProvider answersThatStopTheUploadRun
     */
    public function it_stops_the_upload_run_when_shipmondo_turns_the_store_away(int $statusCode, UploadOutcome $outcome): void
    {
        $turnedAway = $this->order(1);
        $notTried = $this->order(2);
        $this->shipmondo->respond($statusCode);
        $this->entityManager->refresh($notTried, Argument::cetera())->shouldNotBeCalled();

        self::assertSame($outcome, $this->uploader()->upload());

        self::assertSame(['#001'], $this->uploadedOrderNumbers());
        self::assertSame(self::PENDING, $turnedAway->getShipmondoState());
        self::assertSame(self::PENDING, $notTried->getShipmondoState());

        $records = $this->logger->records;
        self::assertCount(2, $records);
        self::assertSame([
            'level' => LogLevel::ERROR,
            'message' => 'The upload run stopped, because Shipmondo answered the upload of the order {order} with a {outcome}. The remaining orders stay pending for the next run',
            'context' => ['order' => '#001', 'outcome' => $outcome->value],
        ], $records[1]);
    }

    /**
     * @return iterable<string, array{int, UploadOutcome}>
     */
    public static function answersThatStopTheUploadRun(): iterable
    {
        yield 'credentials failure (401)' => [401, UploadOutcome::CredentialsFailure];
        yield 'credentials failure (403)' => [403, UploadOutcome::CredentialsFailure];
        yield 'rate limited (429)' => [429, UploadOutcome::RateLimited];
    }

    /**
     * @test
     */
    public function it_stops_the_upload_run_after_three_rejected_uploads_with_no_successful_upload_between_them(): void
    {
        $orders = [$this->order(1), $this->order(2), $this->order(3), $this->order(4)];
        $notTried = $this->order(5);
        $this->shipmondo
            ->respond(422)
            ->respond(500) // a temporary failure doesn't count, and doesn't start the count over either
            ->respond(422)
            ->respond(422)
        ;
        $this->entityManager->refresh($notTried, Argument::cetera())->shouldNotBeCalled();

        self::assertSame(UploadOutcome::Rejected, $this->uploader()->upload());

        self::assertSame(['#001', '#002', '#003', '#004'], $this->uploadedOrderNumbers());
        self::assertSame(
            [self::FAILED, self::PENDING, self::FAILED, self::FAILED, self::PENDING],
            array_map(static fn (Order $order): string => $order->getShipmondoState(), [...$orders, $notTried]),
        );

        $records = $this->logger->records;
        self::assertCount(5, $records);
        self::assertSame([
            'level' => LogLevel::ERROR,
            'message' => 'The upload run stopped after {count} rejected uploads in a row, which suggests a shop-wide problem, such as a payment gateway or shipment template that no longer exists in Shipmondo. The remaining orders stay pending for the next run',
            'context' => ['count' => 3],
        ], $records[4]);
    }

    /**
     * @test
     */
    public function it_starts_counting_rejected_uploads_over_after_a_successful_upload(): void
    {
        $orders = [$this->order(1), $this->order(2), $this->order(3), $this->order(4), $this->order(5), $this->order(6)];
        $notTried = $this->order(7);
        $this->shipmondo
            ->respond(422)
            ->respond(422)
            ->respond(201, ['id' => 1003])
            ->respond(422)
            ->respond(422)
            ->respond(422)
        ;
        $this->entityManager->refresh($notTried, Argument::cetera())->shouldNotBeCalled();

        self::assertSame(UploadOutcome::Rejected, $this->uploader()->upload());

        self::assertSame(['#001', '#002', '#003', '#004', '#005', '#006'], $this->uploadedOrderNumbers());
        self::assertSame(
            [self::FAILED, self::FAILED, self::UPLOADED, self::FAILED, self::FAILED, self::FAILED, self::PENDING],
            array_map(static fn (Order $order): string => $order->getShipmondoState(), [...$orders, $notTried]),
        );
    }

    /**
     * @test
     *
     * @dataProvider envelopesWithoutAnOutcome
     */
    public function it_leaves_the_uploads_to_whoever_handles_the_command_when_it_is_not_handled_synchronously(?HandledStamp $handledStamp): void
    {
        $first = $this->order(1);
        $first->setVersion(7);
        $second = $this->order(2);

        /** @var list<UploadOrder> $dispatched */
        $dispatched = [];
        $commandBus = $this->prophesize(MessageBusInterface::class);
        $commandBus->dispatch(Argument::type(UploadOrder::class))->will(static function (array $args) use (&$dispatched, $handledStamp): Envelope {
            Assert::isInstanceOf($args[0], UploadOrder::class);
            $dispatched[] = $args[0];

            return new Envelope($args[0], null === $handledStamp ? [] : [$handledStamp]);
        });
        $this->commandBus = $commandBus->reveal();

        self::assertNull($this->uploader()->upload());

        self::assertSame([[1, 7], [2, 1]], array_map(static fn (UploadOrder $message): array => [$message->order, $message->version], $dispatched));
        self::assertSame(self::UPLOADING, $first->getShipmondoState());
        self::assertSame(self::UPLOADING, $second->getShipmondoState());
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{HandledStamp|null}>
     */
    public static function envelopesWithoutAnOutcome(): iterable
    {
        yield 'sent to an asynchronous transport' => [null];
        yield 'handled by a handler that returns something else' => [new HandledStamp('uploaded', 'custom_handler')];
    }

    private function order(int $id, string $state = self::PENDING): Order
    {
        $order = new Order();
        (new \ReflectionProperty($order, 'id'))->setValue($order, $id);
        $order->setNumber(sprintf('#%03d', $id));
        $order->setShipmondoState($state);

        return $this->orders[] = $order;
    }

    private function uploader(): OrderUploader
    {
        $orders = $this->orders;

        $provider = $this->prophesize(PreQualifiedUploadableOrdersProviderInterface::class);
        $provider->getOrders()->willReturn($orders);

        $managerRegistry = $this->prophesize(ManagerRegistry::class);
        $managerRegistry->getManagerForClass(Order::class)->willReturn($this->entityManager->reveal());

        $orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        foreach ($orders as $order) {
            $orderRepository->find($order->getId())->willReturn($order);
        }

        /** @var UploadOrderHandler|null $handler */
        $handler = null;
        $commandBus = $this->commandBus ?? new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            UploadOrder::class => [static function (UploadOrder $message) use (&$handler): UploadOutcome {
                Assert::isInstanceOf($handler, UploadOrderHandler::class);

                return $handler($message);
            }],
        ]))]);

        $uploader = new OrderUploader(
            $provider->reveal(),
            $commandBus,
            OrderWorkflow::getWorkflow(new EventDispatcher()),
            ShipmondoApi::client($this->shipmondo),
            $this->salesOrderDataMapper->reveal(),
            $this->logger,
            $managerRegistry->reveal(),
        );
        $handler = new UploadOrderHandler($orderRepository->reveal(), $uploader);

        return $uploader;
    }

    /**
     * The order numbers of the sales orders Shipmondo was asked to create, in order
     *
     * @return list<mixed>
     */
    private function uploadedOrderNumbers(): array
    {
        return array_map(static function ($request): mixed {
            self::assertSame('POST', $request->getMethod());

            $body = json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($body);

            return $body['order_id'] ?? null;
        }, $this->shipmondo->requests());
    }

    /**
     * The log records, with each logged exception replaced by its class
     *
     * @return list<array{level: mixed, message: string, context: array<array-key, mixed>}>
     */
    private function logRecords(): array
    {
        return array_map(static function (array $record): array {
            $exception = $record['context']['exception'] ?? null;
            if ($exception instanceof \Throwable) {
                $record['context']['exception'] = $exception::class;
            }

            return $record;
        }, $this->logger->records);
    }
}
