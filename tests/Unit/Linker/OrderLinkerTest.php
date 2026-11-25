<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Linker;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Exception\ConnectionException;
use Setono\Shipmondo\Exception\ForbiddenException;
use Setono\Shipmondo\Exception\InternalServerErrorException;
use Setono\Shipmondo\Exception\MalformedResponseException;
use Setono\Shipmondo\Exception\NotFoundException;
use Setono\Shipmondo\Exception\TooManyRequestsException;
use Setono\Shipmondo\Exception\UnauthorizedException;
use Setono\Shipmondo\Exception\ValidationException;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCanceller;
use Setono\SyliusShipmondoPlugin\Failure\RecordedShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Setono\SyliusShipmondoPlugin\Linker\OrderLinker;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\DeleteOrderHandler;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Provider\OrderIdProvider;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Workflow\Event\GuardEvent;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Support\SalesOrders;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Orders;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Webmozart\Assert\Assert;

/**
 * Exercises the sales order lookup through its interface with the real order state machine, the real Shipmondo SDK over
 * a scripted HTTP transport, and the real canceller, whose DeleteOrder command a command bus handles synchronously with
 * the real handler
 */
final class OrderLinkerTest extends TestCase
{
    use ProphecyTrait;

    private const PENDING = OrderInterface::SHIPMONDO_STATE_PENDING;

    private const UPLOADED = OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO;

    private const UNLINKED = OrderInterface::SHIPMONDO_STATE_UNLINKED;

    private const CANCELLED = OrderInterface::SHIPMONDO_STATE_CANCELLED;

    private const FAILED_MESSAGE = 'The sales order lookup of the unlinked order {order} failed, so the order stays unlinked for the next upload run';

    private const CANCELLED_MESSAGE = 'The unlinked order {order} was cancelled, so it becomes cancelled once Shipmondo has no sales order for it';

    private ScriptedHttpClient $shipmondo;

    private RecordingLogger $logger;

    /**
     * The order state machine's event dispatcher, for guards a store adds
     */
    private EventDispatcher $eventDispatcher;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    /**
     * Gives the order id the upload sent, and so the one a sales order lookup searches by
     */
    private OrderIdProvider $orderIdProvider;

    /**
     * The orders the repository finds, e.g. for the DeleteOrder command
     *
     * @var list<Order>
     */
    private array $orders = [];

    /**
     * Whether the command bus fails to send DeleteOrder, as when its asynchronous transport is down
     */
    private bool $deleteOrderFails = false;

    protected function setUp(): void
    {
        $this->shipmondo = new ScriptedHttpClient();
        $this->logger = new RecordingLogger();
        $this->orderIdProvider = new OrderIdProvider();
        $this->eventDispatcher = new EventDispatcher();

        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->will(static function (array $args): mixed {
            Assert::isCallable($args[0]);

            return $args[0]();
        });
        $this->entityManager->refresh(Argument::cetera())->will(static function (): void {
        });
    }

    /**
     * The upload created the sales order, but which is unknown, so it is looked up by the order id the upload sent
     *
     * @test
     */
    public function it_links_an_unlinked_order_to_the_one_sales_order_shipmondo_has_with_its_order_id(): void
    {
        $order = $this->unlinkedOrder();
        $this->respondWithSalesOrders([1001 => '#001']);

        // the lookup is settled on the order as the database has it, locked, so nothing changes it in between
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledOnce();
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE)->shouldBeCalledOnce();

        self::assertNull($this->linker()->link($order));

        self::assertSame([self::UPLOADED, 1001], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'A sales order lookup found the sales order {salesOrder} for the unlinked order {order}, so the order is uploaded and linked to it',
            'context' => ['order' => '#001', 'salesOrder' => 1001],
        ]], $this->logger->records);
    }

    /**
     * A shop that changes its order ids, e.g. with the order id prefix, has its sales orders found by the exact order id it
     * sent, not by another shop's sales order with the order number alone
     *
     * @test
     */
    public function it_looks_up_the_sales_order_by_the_order_id_the_order_id_provider_gives(): void
    {
        $this->orderIdProvider = new OrderIdProvider('WEB-');
        $order = $this->unlinkedOrder();
        $this->respondWithSalesOrders([1001 => '#001', 1002 => 'WEB-#001']);

        self::assertNull($this->linker()->link($order));

        self::assertSame('order_id=WEB-%23001&page=1&per_page=25', $this->shipmondo->requests()[0]->getUri()->getQuery());
        self::assertSame([self::UPLOADED, 1002], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * Shipmondo lists archived sales orders too when no archived filter is sent, so an order whose sales order was
     * archived is still found
     *
     * @test
     */
    public function it_looks_up_the_sales_order_by_the_order_id_the_upload_sent_whether_or_not_it_is_archived(): void
    {
        $order = $this->unlinkedOrder();
        $this->respondWithSalesOrders([]);

        $this->linker()->link($order);

        $request = $this->shipmondo->requests()[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('order_id=%23001&page=1&per_page=25', $request->getUri()->getQuery());
    }

    /**
     * Someone deleted the sales order in Shipmondo, or the upload's call that failed temporarily didn't create one
     *
     * @test
     */
    public function it_puts_an_unlinked_order_back_to_pending_when_a_lookup_finds_no_sales_order(): void
    {
        $order = $this->unlinkedOrder();
        $this->respondWithSalesOrders([]);

        self::assertNull($this->linker()->link($order));

        self::assertSame([self::PENDING, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'A sales order lookup found no sales order for the unlinked order {order}, so the order goes back to pending to be uploaded again',
            'context' => ['order' => '#001'],
        ]], $this->logger->records);
    }

    /**
     * Shipmondo's order_id filter matches exactly, but that isn't documented, so the lookup compares the order ids too
     *
     * @test
     */
    public function it_ignores_the_sales_orders_a_lookup_returns_with_another_order_id(): void
    {
        $order = $this->unlinkedOrder();
        $this->respondWithSalesOrders([1001 => '#0011', 1002 => '#001', 1003 => '001', 1004 => null, 1005 => '#001 ']);

        self::assertNull($this->linker()->link($order));

        self::assertSame([self::UPLOADED, 1002], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @test
     */
    public function it_puts_an_unlinked_order_back_to_pending_when_a_lookup_returns_only_sales_orders_with_another_order_id(): void
    {
        $order = $this->unlinkedOrder(OrderInterface::STATE_CANCELLED);
        $this->respondWithSalesOrders([1001 => '#0011']);

        self::assertNull($this->linker()->link($order));

        // cancelled right away, since Shipmondo has no sales order for it
        self::assertSame([self::CANCELLED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
    }

    /**
     * Some may be another shop's on the same Shipmondo account, so the order is linked to none of them
     *
     * @test
     */
    public function it_leaves_an_unlinked_order_unlinked_when_a_lookup_finds_several_sales_orders(): void
    {
        $order = $this->unlinkedOrder(failure: new RecordedShipmondoFailure(ShipmondoFailureKind::UnreadSuccess, 201, 'Unreadable'));
        $this->respondWithSalesOrders([1001 => '#001', 1002 => 'another', 1003 => '#001']);
        $this->entityManager->wrapInTransaction(Argument::cetera())->shouldNotBeCalled();

        self::assertNull($this->linker()->link($order));

        self::assertSame([self::UNLINKED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        // it still explains why the order is unlinked
        self::assertEquals(new RecordedShipmondoFailure(ShipmondoFailureKind::UnreadSuccess, 201, 'Unreadable'), $order->getShipmondoFailure());
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => 'A sales order lookup found several sales orders with the order id of the unlinked order {order}, so the order stays unlinked: they may be duplicates, or another shop\'s on the same Shipmondo account. Check the sales orders {salesOrders} in Shipmondo',
            'context' => ['order' => '#001', 'salesOrders' => [1001, 1003]],
        ]], $this->logger->records);
    }

    /**
     * Nothing is known about whether Shipmondo has a sales order for it, so it stays unlinked
     *
     * @test
     */
    public function it_leaves_an_unlinked_order_that_is_cancelled_in_sylius_unlinked_when_a_lookup_finds_several_sales_orders(): void
    {
        $order = $this->unlinkedOrder(OrderInterface::STATE_CANCELLED);
        $this->respondWithSalesOrders([1001 => '#001', 1002 => '#001']);

        self::assertNull($this->linker()->link($order));

        self::assertSame([self::UNLINKED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
        self::assertCount(1, $this->logger->records);
    }

    /**
     * @test
     */
    public function it_deletes_the_sales_order_a_lookup_finds_for_an_unlinked_order_that_is_cancelled_in_sylius(): void
    {
        $order = $this->unlinkedOrder(OrderInterface::STATE_CANCELLED);
        $this->respondWithSalesOrders([1001 => '#001']);
        $this->shipmondo->respond(204);
        // the order is locked and read again to settle its lookup, and again to make it cancelled
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledTimes(2);
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE)->shouldBeCalledTimes(2);

        self::assertNull($this->linker()->link($order));

        self::assertSame([self::CANCELLED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders', 'DELETE sales_orders/1001'], $this->shipmondo->requestLines());
        self::assertSame([
            'level' => LogLevel::INFO,
            'message' => self::CANCELLED_MESSAGE,
            'context' => ['order' => '#001', 'salesOrder' => 1001],
        ], $this->logger->records[1]);
    }

    /**
     * @test
     */
    public function it_cancels_an_unlinked_order_that_is_cancelled_in_sylius_when_a_lookup_finds_no_sales_order(): void
    {
        $order = $this->unlinkedOrder(OrderInterface::STATE_CANCELLED);
        $this->respondWithSalesOrders([]);
        $this->entityManager->wrapInTransaction(Argument::type('callable'))->shouldBeCalledTimes(2);

        self::assertNull($this->linker()->link($order));

        self::assertSame([self::CANCELLED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
        self::assertSame([
            'level' => LogLevel::INFO,
            'message' => self::CANCELLED_MESSAGE,
            'context' => ['order' => '#001', 'salesOrder' => null],
        ], $this->logger->records[1]);
    }

    /**
     * Its sales order is deleted right away, also when the command bus would send DeleteOrder to an asynchronous
     * transport, which may be down. The failure is logged, not thrown, so the upload run goes on
     *
     * @test
     */
    public function it_keeps_an_order_it_linked_uploaded_when_the_command_that_deletes_its_sales_order_cannot_be_sent(): void
    {
        $order = $this->unlinkedOrder(OrderInterface::STATE_CANCELLED);
        $this->deleteOrderFails = true;
        $this->respondWithSalesOrders([1001 => '#001']);

        self::assertNull($this->linker()->link($order));

        self::assertSame([self::UPLOADED, 1001], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
        self::assertSame([
            'level' => LogLevel::ERROR,
            'message' => 'The sales order of the cancelled order {order} could not be deleted, because the command that deletes it failed, so the order stays uploaded and linked to it. Delete the sales order in Shipmondo',
            'context' => ['order' => '#001', 'salesOrder' => 1001, 'exception' => TransportException::class],
        ], $this->logRecords()[2]);
    }

    /**
     * E.g. another upload run looked it up meanwhile: it may have linked it, or even uploaded it again
     *
     * @test
     *
     * @dataProvider changesWhileLookingUp
     *
     * @param \Closure(Order): void $change
     */
    public function it_leaves_an_order_that_changed_while_its_sales_order_was_looked_up_as_it_is(\Closure $change, string $state, ?int $salesOrderId): void
    {
        $order = $this->unlinkedOrder();
        $this->respondWithSalesOrders([]);
        $this->entityManager->refresh($order, LockMode::PESSIMISTIC_WRITE)->will(static function () use ($order, $change): void {
            $change($order);
        });

        self::assertNull($this->linker()->link($order));

        self::assertSame([$state, $salesOrderId], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
        self::assertSame([[
            'level' => LogLevel::INFO,
            'message' => 'The sales order lookup of the order {order} was not settled, because the order changed while Shipmondo was searched, or a guard on the order workflow holds it back, so it is left as it is',
            'context' => ['order' => '#001', 'state' => $state],
        ]], $this->logger->records);
    }

    /**
     * @return iterable<string, array{\Closure(Order): void, string, int|null}>
     */
    public static function changesWhileLookingUp(): iterable
    {
        yield 'linked by another upload run' => [
            static function (Order $order): void {
                $order->setShipmondoState(self::UPLOADED, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 1001]);
                $order->setVersion(2);
            },
            self::UPLOADED,
            1001,
        ];
        // e.g. another run found no sales order, uploaded it again, and that upload failed temporarily
        yield 'still unlinked, but changed' => [
            static function (Order $order): void {
                $order->setVersion(4);
            },
            self::UNLINKED,
            null,
        ];
    }

    /**
     * @test
     *
     * @dataProvider lookupTransitions
     *
     * @param array<int, string> $salesOrders
     */
    public function it_leaves_an_unlinked_order_a_guard_on_the_order_workflow_holds_back_as_it_is(array $salesOrders, string $transition): void
    {
        $order = $this->unlinkedOrder();
        $this->respondWithSalesOrders($salesOrders);
        $this->eventDispatcher->addListener(
            'workflow.setono_sylius_shipmondo__order.guard.' . $transition,
            static function (GuardEvent $event): void {
                $event->setBlocked(true);
            },
        );

        self::assertNull($this->linker()->link($order));

        self::assertSame([self::UNLINKED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
    }

    /**
     * @return iterable<string, array{array<int, string>, string}>
     */
    public static function lookupTransitions(): iterable
    {
        yield 'link' => [[1001 => '#001'], OrderWorkflow::TRANSITION_LINK];
        yield 'retry' => [[], OrderWorkflow::TRANSITION_RETRY];
    }

    /**
     * The failure that made the order unlinked no longer explains its Shipmondo state once a lookup settles it
     *
     * @test
     *
     * @dataProvider settlingLookups
     *
     * @param array<int, string> $salesOrders
     */
    public function it_clears_the_shipmondo_failure_of_an_unlinked_order_its_lookup_settles(array $salesOrders, string $state): void
    {
        $order = $this->unlinkedOrder(failure: new RecordedShipmondoFailure(ShipmondoFailureKind::TemporaryFailure, 500, 'Internal Server Error'));
        $this->respondWithSalesOrders($salesOrders);

        self::assertNull($this->linker()->link($order));

        self::assertSame($state, $order->getShipmondoState());
        self::assertNull($order->getShipmondoFailure());
    }

    /**
     * @return iterable<string, array{array<int, string>, string}>
     */
    public static function settlingLookups(): iterable
    {
        yield 'one sales order found (link)' => [[1001 => '#001'], self::UPLOADED];
        yield 'none found (retry)' => [[], self::PENDING];
    }

    /**
     * The order stays unlinked for the next upload run to look it up again, and the failure tells the run's stop rules
     * how the lookup went. A failure expected to pass, or a rate limit, is a warning; any other is an error
     *
     * @test
     *
     * @dataProvider failedLookups
     *
     * @param \Closure(ScriptedHttpClient): mixed $fail
     */
    public function it_leaves_an_unlinked_order_unlinked_and_returns_why_when_shipmondo_cannot_be_searched(
        \Closure $fail,
        ShipmondoFailureKind $kind,
        string $level,
        string $exception,
        ?int $statusCode,
    ): void {
        $order = $this->unlinkedOrder();
        $fail($this->shipmondo);
        $this->entityManager->wrapInTransaction(Argument::cetera())->shouldNotBeCalled();

        $failure = $this->linker()->link($order);

        self::assertSame($kind, $failure?->kind);
        self::assertSame([self::UNLINKED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(['GET sales_orders'], $this->shipmondo->requestLines());
        self::assertCount(1, $this->logger->records);
        $record = $this->logRecords()[0];
        self::assertSame(
            [$level, self::FAILED_MESSAGE, '#001', $exception, $kind->value, $statusCode],
            [$record['level'], $record['message'], $record['context']['order'], $record['context']['exception'], $record['context']['shipmondoFailure'], $record['context']['shipmondoStatusCode']],
        );
    }

    /**
     * @return iterable<string, array{\Closure(ScriptedHttpClient): mixed, ShipmondoFailureKind, string, string, int|null}>
     */
    public static function failedLookups(): iterable
    {
        yield 'server error' => [static fn (ScriptedHttpClient $shipmondo): mixed => $shipmondo->respond(500), ShipmondoFailureKind::TemporaryFailure, LogLevel::WARNING, InternalServerErrorException::class, 500];
        yield 'no answer' => [static fn (ScriptedHttpClient $shipmondo): mixed => $shipmondo->failToConnect(), ShipmondoFailureKind::TemporaryFailure, LogLevel::WARNING, ConnectionException::class, null];
        yield 'a success that did not arrive in full' => [static fn (ScriptedHttpClient $shipmondo): mixed => $shipmondo->respondWithAnUnreadableBody(200), ShipmondoFailureKind::TemporaryFailure, LogLevel::WARNING, ConnectionException::class, 200];
        yield 'rate limited' => [static fn (ScriptedHttpClient $shipmondo): mixed => $shipmondo->respond(429), ShipmondoFailureKind::RateLimited, LogLevel::WARNING, TooManyRequestsException::class, 429];
        yield '401 Unauthorized' => [static fn (ScriptedHttpClient $shipmondo): mixed => $shipmondo->respond(401), ShipmondoFailureKind::CredentialsFailure, LogLevel::ERROR, UnauthorizedException::class, 401];
        yield '403 Forbidden' => [static fn (ScriptedHttpClient $shipmondo): mixed => $shipmondo->respond(403), ShipmondoFailureKind::CredentialsFailure, LogLevel::ERROR, ForbiddenException::class, 403];
        yield 'not found' => [static fn (ScriptedHttpClient $shipmondo): mixed => $shipmondo->respond(404), ShipmondoFailureKind::NotFound, LogLevel::ERROR, NotFoundException::class, 404];
        yield 'rejected' => [static fn (ScriptedHttpClient $shipmondo): mixed => $shipmondo->respond(422), ShipmondoFailureKind::Rejected, LogLevel::ERROR, ValidationException::class, 422];
        yield 'a success that cannot be read' => [static fn (ScriptedHttpClient $shipmondo): mixed => $shipmondo->respond(200, body: 'not JSON'), ShipmondoFailureKind::UnexpectedFailure, LogLevel::ERROR, MalformedResponseException::class, 200];
    }

    /**
     * Only an unlinked order may have a sales order nobody knows of. Settling another by what Shipmondo has could
     * e.g. put a failed order back to pending
     *
     * @test
     *
     * @dataProvider ordersThatAreNotUnlinked
     */
    public function it_leaves_an_order_that_is_not_unlinked_alone_without_searching_shipmondo(string $state, ?int $salesOrderId): void
    {
        $order = Orders::create($state, $salesOrderId, id: 1, number: '#001');

        self::assertNull($this->linker()->link($order));

        self::assertSame([$state, $salesOrderId], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame([], $this->shipmondo->requestLines());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function ordersThatAreNotUnlinked(): iterable
    {
        yield 'pending' => [self::PENDING, null];
        yield 'uploading' => [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO, null];
        yield 'uploaded' => [self::UPLOADED, 999];
        yield 'failed' => [OrderInterface::SHIPMONDO_STATE_FAILED, null];
        yield 'cancelled' => [self::CANCELLED, null];
    }

    /**
     * An unlinked order that is due a sales order lookup, made unlinked by the given Shipmondo failure, if any
     */
    private function unlinkedOrder(string $state = OrderInterface::STATE_NEW, ?RecordedShipmondoFailure $failure = null): Order
    {
        return $this->orders[] = Orders::create(self::UNLINKED, state: $state, id: 1, number: '#001', failure: $failure);
    }

    /**
     * Answers a sales order lookup with these sales orders, by their id and order id
     *
     * @param array<int, string|null> $orderIds
     */
    private function respondWithSalesOrders(array $orderIds): void
    {
        $this->shipmondo->respond(200, SalesOrders::withOrderIds($orderIds));
    }

    private function linker(): OrderLinker
    {
        $managerRegistry = $this->prophesize(ManagerRegistry::class);
        $managerRegistry->getManagerForClass(Order::class)->willReturn($this->entityManager->reveal());

        $orderWorkflow = OrderWorkflow::getWorkflow($this->eventDispatcher);
        $shipmondoClient = ShipmondoApi::client($this->shipmondo);

        /** @var DeleteOrderHandler|null $deleteOrderHandler */
        $deleteOrderHandler = null;
        $commandBus = new MessageBus([
            // stands in for the send_message middleware, which fails to send a DeleteOrder command to its transport
            new class($this->deleteOrderFails) implements MiddlewareInterface {
                public function __construct(private readonly bool $fails)
                {
                }

                public function handle(Envelope $envelope, StackInterface $stack): Envelope
                {
                    if ($this->fails) {
                        throw new TransportException('The transport is down');
                    }

                    return $stack->next()->handle($envelope, $stack);
                }
            },
            new HandleMessageMiddleware(new HandlersLocator([
                DeleteOrder::class => [static function (DeleteOrder $message) use (&$deleteOrderHandler): bool {
                    Assert::isInstanceOf($deleteOrderHandler, DeleteOrderHandler::class);

                    return $deleteOrderHandler($message);
                }],
            ])),
        ]);

        $orderCanceller = new OrderCanceller($commandBus, $orderWorkflow, $this->logger);

        $orderRepository = $this->prophesize(OrderRepositoryInterface::class);
        foreach ($this->orders as $order) {
            $orderRepository->find($order->getId())->willReturn($order);
        }
        $deleteOrderHandler = new DeleteOrderHandler($orderRepository->reveal(), $shipmondoClient, $orderWorkflow, $orderCanceller);

        return new OrderLinker($this->orderIdProvider, $shipmondoClient, $orderWorkflow, $orderCanceller, $this->logger, $managerRegistry->reveal());
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
