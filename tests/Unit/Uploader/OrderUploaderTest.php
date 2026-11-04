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
use Setono\SyliusShipmondoPlugin\Canceller\OrderCanceller;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\DeleteOrderHandler;
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
use Symfony\Component\Workflow\Event\GuardEvent;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Support\NetworkException;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Webmozart\Assert\Assert;

/**
 * Exercises the uploader through its interface with the real order state machine, the real Shipmondo SDK over a
 * scripted HTTP transport, and a real command bus that handles UploadOrder and DeleteOrder synchronously with the real
 * handlers.
 */
final class OrderUploaderTest extends TestCase
{
    use ProphecyTrait;

    private const PENDING = OrderInterface::SHIPMONDO_STATE_PENDING;

    private const UPLOADING = OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO;

    private const UPLOADED = OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO;

    private const FAILED = OrderInterface::SHIPMONDO_STATE_FAILED;

    private const CANCELLED = OrderInterface::SHIPMONDO_STATE_CANCELLED;

    private const CANCELLED_WHILE_UPLOADING_MESSAGE = 'The order {order} was cancelled while it was uploading, so its cancellation is carried over to Shipmondo';

    private const REJECTED_MESSAGE = 'Shipmondo rejected the upload of the order {order}, so the order is failed';

    private const CREDENTIALS_FAILURE_MESSAGE = 'Shipmondo did not accept the API credentials when uploading the order {order}, so the order goes back to pending';

    private const RATE_LIMITED_MESSAGE = 'Shipmondo rate limited the upload of the order {order}, so the order goes back to pending';

    private const TEMPORARY_FAILURE_MESSAGE = 'The upload of the order {order} failed temporarily, so the order goes back to pending';

    private const UNEXPECTED_FAILURE_MESSAGE = 'The upload of the order {order} failed unexpectedly, so the order is failed';

    private ScriptedHttpClient $shipmondo;

    private RecordingLogger $logger;

    /**
     * The order state machine's event dispatcher, for guards a store adds
     */
    private EventDispatcher $eventDispatcher;

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
        $this->eventDispatcher = new EventDispatcher();

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
    }

    /**
     * @test
     */
    public function it_uploads_an_order_and_links_it_to_its_sales_order(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $this->shipmondo->respond(201, ['id' => 1001]);

        // the outcome is recorded on the order as the database has it, locked, so nothing changes it in between
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledOnce();
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE)->will(static function () use ($order): void {
            // what the database has: uploading, not linked to a sales order
            $order->setShipmondoState(self::UPLOADING);
        })->shouldBeCalledOnce();

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
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledOnce();
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE)->shouldBeCalledOnce();

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

        yield '302 Found' => [302, UnexpectedStatusCodeException::class, UploadOutcome::UnexpectedFailure, self::FAILED, LogLevel::ERROR, self::UNEXPECTED_FAILURE_MESSAGE];
        yield '399' => [399, UnexpectedStatusCodeException::class, UploadOutcome::UnexpectedFailure, self::FAILED, LogLevel::ERROR, self::UNEXPECTED_FAILURE_MESSAGE];
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
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledOnce();

        self::assertSame(UploadOutcome::UnexpectedFailure, $this->uploader()->uploadOrder($order));

        self::assertSame(self::FAILED, $order->getShipmondoState());
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => self::UNEXPECTED_FAILURE_MESSAGE,
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
            'message' => self::UNEXPECTED_FAILURE_MESSAGE,
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
    public function it_sends_the_order_back_to_pending_when_it_changed_while_it_was_uploading(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $order->setVersion(5);
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledOnce();

        self::assertSame(UploadOutcome::ChangedWhileUploading, $this->uploader()->uploadOrder($order, 4));

        self::assertSame(self::PENDING, $order->getShipmondoState());
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'The order {order} changed while it was uploading, so it goes back to pending without being uploaded',
            'context' => ['order' => '#001', 'outcome' => 'changed_while_uploading', 'state' => self::PENDING, 'salesOrder' => null],
        ]], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_uploads_an_order_that_did_not_change_while_it_was_uploading(): void
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
    public function it_leaves_an_order_alone_that_is_not_waiting_for_an_upload(string $state, ?int $salesOrderId): void
    {
        $order = $this->order(1, $state);
        $this->entityManager->wrapInTransaction(Argument::cetera())->shouldNotBeCalled();

        self::assertSame(UploadOutcome::Skipped, $this->uploader()->uploadOrder($order));

        self::assertSame($state, $order->getShipmondoState());
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'The order {order} was not uploaded, because its Shipmondo state is {state}, not uploading',
            'context' => ['order' => '#001', 'outcome' => 'skipped', 'state' => $state, 'salesOrder' => $salesOrderId],
        ]], $this->logger->records);
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function statesNotWaitingForAnUpload(): iterable
    {
        yield 'pending' => [self::PENDING, null];
        yield 'uploaded' => [self::UPLOADED, 999];
        yield 'failed' => [self::FAILED, null];
        yield 'cancelled' => [self::CANCELLED, null];
    }

    /**
     * The order was cancelled while Shipmondo was creating its sales order. The cancellation couldn't delete a sales
     * order the order wasn't linked to yet, so the upload deletes it
     *
     * @test
     */
    public function it_deletes_the_sales_order_it_just_created_when_the_order_was_cancelled_while_it_was_uploading(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $this->cancelWhenReadAgain($order);
        $this->shipmondo->respond(201, ['id' => 1001])->respond(204);
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledTimes(2);

        // the upload still reports how it went
        self::assertSame(UploadOutcome::Uploaded, $this->uploader()->uploadOrder($order));

        self::assertSame([self::CANCELLED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame([['POST', '/sales_orders'], ['DELETE', '/sales_orders/1001']], $this->shipmondoRequests());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'The order {order} was uploaded to Shipmondo as the sales order {salesOrder}',
            'context' => ['order' => '#001', 'outcome' => 'uploaded', 'state' => self::UPLOADED, 'salesOrder' => 1001],
        ], [
            'level' => LogLevel::INFO,
            'message' => self::CANCELLED_WHILE_UPLOADING_MESSAGE,
            'context' => ['order' => '#001', 'salesOrder' => 1001],
        ]], $this->logRecords());
    }

    /**
     * @test
     */
    public function it_leaves_the_order_uploaded_when_the_sales_order_of_an_order_cancelled_while_it_was_uploading_cannot_be_deleted(): void
    {
        $order = $this->order(1, self::UPLOADING);
        $this->cancelWhenReadAgain($order);
        $this->shipmondo->respond(201, ['id' => 1001])->respond(500, ['error' => 'Internal Server Error']);

        self::assertSame(UploadOutcome::Uploaded, $this->uploader()->uploadOrder($order));

        // the sales order still exists in Shipmondo, so the order stays linked to it
        self::assertSame([self::UPLOADED, 1001], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(
            ['The order {order} was uploaded to Shipmondo as the sales order {salesOrder}', self::CANCELLED_WHILE_UPLOADING_MESSAGE, 'Could not delete the Shipmondo sales order {shipmondoId} of the cancelled order {order}'],
            array_column($this->logger->records, 'message'),
        );
    }

    /**
     * @test
     *
     * @dataProvider uploadsThatCreatedNoSalesOrder
     */
    public function it_cancels_an_order_that_was_cancelled_while_it_was_uploading_when_no_sales_order_was_created(
        int $statusCode,
        ?int $version,
        UploadOutcome $outcome,
        string $settledState,
    ): void {
        $order = $this->order(1, self::UPLOADING);
        $order->setVersion(5);
        $this->cancelWhenReadAgain($order);
        $this->shipmondo->respond($statusCode, ['error' => 'No']);
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledTimes(2);

        // the upload still reports how it went, so a failure that says something about the store still counts
        self::assertSame($outcome, $this->uploader()->uploadOrder($order, $version));

        self::assertSame([self::CANCELLED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame([
            [$outcome->value, $settledState],
            [null, null],
        ], array_map(static fn (array $record): array => [$record['context']['outcome'] ?? null, $record['context']['state'] ?? null], $this->logger->records));
        self::assertSame(['order' => '#001', 'salesOrder' => null], $this->logger->records[1]['context']);
        self::assertSame(self::CANCELLED_WHILE_UPLOADING_MESSAGE, $this->logger->records[1]['message']);
    }

    /**
     * @return iterable<string, array{int, int|null, UploadOutcome, string}>
     */
    public static function uploadsThatCreatedNoSalesOrder(): iterable
    {
        yield 'rejected upload (failed)' => [422, null, UploadOutcome::Rejected, self::FAILED];
        yield 'credentials failure (back to pending)' => [401, null, UploadOutcome::CredentialsFailure, self::PENDING];
        yield 'changed while uploading (back to pending)' => [201, 4, UploadOutcome::ChangedWhileUploading, self::PENDING];
    }

    /**
     * @test
     */
    public function it_claims_and_uploads_every_order_that_qualifies_for_upload(): void
    {
        $first = $this->order(1);
        $second = $this->order(2);
        $this->shipmondo->respond(201, ['id' => 1001])->respond(201, ['id' => 1002]);

        // each order's row is locked and read again before it is claimed, and again before its outcome is recorded
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledTimes(4);
        $this->entityManager->refresh($first, LockMode::PESSIMISTIC_WRITE)->shouldBeCalledTimes(2);
        $this->entityManager->refresh($second, LockMode::PESSIMISTIC_WRITE)->shouldBeCalledTimes(2);

        self::assertNull($this->uploader()->upload());

        self::assertSame(['#001', '#002'], $this->uploadedOrderNumbers());
        self::assertSame([self::UPLOADED, 1001], [$first->getShipmondoState(), $first->getShipmondoId()]);
        self::assertSame([self::UPLOADED, 1002], [$second->getShipmondoState(), $second->getShipmondoId()]);
    }

    /**
     * @test
     */
    public function it_skips_an_order_that_changed_after_the_upload_run_loaded_it_so_the_next_run_checks_it_again(): void
    {
        $changed = $this->order(1);
        $unchanged = $this->order(2);
        $this->shipmondo->respond(201, ['id' => 1002]);

        // reading the order again reveals that something changed it after the run loaded it, e.g. an admin cancelled it
        $this->entityManager->refresh($changed, LockMode::PESSIMISTIC_WRITE)->will(static function () use ($changed): void {
            $changed->setVersion(2);
        });

        self::assertNull($this->uploader()->upload());

        self::assertSame(['#002'], $this->uploadedOrderNumbers());
        // nothing at all was attempted for the skipped order, so only the other upload was logged
        self::assertSame([['#002', 'uploaded']], $this->loggedOutcomes());
        self::assertSame(self::PENDING, $changed->getShipmondoState());
        self::assertSame(self::UPLOADED, $unchanged->getShipmondoState());
    }

    /**
     * @test
     */
    public function it_skips_an_order_a_guard_on_the_order_workflow_holds_back(): void
    {
        $heldBack = $this->order(1);
        $other = $this->order(2);
        $this->shipmondo->respond(201, ['id' => 1002]);

        $this->eventDispatcher->addListener(
            'workflow.setono_sylius_shipmondo__order.guard.start_upload',
            static function (GuardEvent $event) use ($heldBack): void {
                $event->setBlocked($heldBack === $event->getSubject());
            },
        );

        self::assertNull($this->uploader()->upload());

        self::assertSame(['#002'], $this->uploadedOrderNumbers());
        self::assertSame([['#002', 'uploaded']], $this->loggedOutcomes());
        self::assertSame(self::PENDING, $heldBack->getShipmondoState());
        self::assertSame(self::UPLOADED, $other->getShipmondoState());
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

    /**
     * An order in the given Shipmondo state. An uploaded order is linked to the sales order 999
     */
    private function order(int $id, string $state = self::PENDING): Order
    {
        $order = new Order();
        (new \ReflectionProperty($order, 'id'))->setValue($order, $id);
        $order->setNumber(sprintf('#%03d', $id));
        $order->setShipmondoState($state, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 999]);

        return $this->orders[] = $order;
    }

    /**
     * Reading the order again to settle its upload reveals that an admin cancelled it in the meantime
     */
    private function cancelWhenReadAgain(Order $order): void
    {
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE)->will(static function () use ($order): void {
            $order->setState(OrderInterface::STATE_CANCELLED);
        });
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

        $orderWorkflow = OrderWorkflow::getWorkflow($this->eventDispatcher);
        $shipmondoClient = ShipmondoApi::client($this->shipmondo);

        /** @var UploadOrderHandler|null $handler */
        $handler = null;
        $commandBus = $this->commandBus ?? new MessageBus([new HandleMessageMiddleware(new HandlersLocator([
            UploadOrder::class => [static function (UploadOrder $message) use (&$handler): UploadOutcome {
                Assert::isInstanceOf($handler, UploadOrderHandler::class);

                return $handler($message);
            }],
            DeleteOrder::class => [new DeleteOrderHandler($orderRepository->reveal(), $shipmondoClient, $orderWorkflow, $this->logger)],
        ]))]);

        $uploader = new OrderUploader(
            $provider->reveal(),
            $commandBus,
            $orderWorkflow,
            $shipmondoClient,
            $this->salesOrderDataMapper->reveal(),
            new OrderCanceller($commandBus, $orderWorkflow),
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
     * The method and path of each request Shipmondo got, in order
     *
     * @return list<array{string, string}>
     */
    private function shipmondoRequests(): array
    {
        return array_map(
            static fn ($request): array => [$request->getMethod(), str_replace('/api/public/v3', '', $request->getUri()->getPath())],
            $this->shipmondo->requests(),
        );
    }

    /**
     * The order and outcome of each log record about an upload, in order
     *
     * @return list<array{mixed, mixed}>
     */
    private function loggedOutcomes(): array
    {
        return array_map(
            static fn (array $record): array => [$record['context']['order'] ?? null, $record['context']['outcome'] ?? null],
            $this->logger->records,
        );
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
