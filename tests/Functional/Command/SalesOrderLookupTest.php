<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Command;

use Psr\Http\Message\RequestInterface;
use Setono\SyliusShipmondoPlugin\Event\PreQualifiedUploadableOrdersQueryBuilderCreatedEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Symfony\Component\Console\Command\Command;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Tests\Setono\SyliusShipmondoPlugin\Support\CommandErrors;
use Tests\Setono\SyliusShipmondoPlugin\Support\SalesOrders;

/**
 * The sales order lookups an upload run makes before it uploads the pending orders, driven through the upload command
 * against the database
 */
final class SalesOrderLookupTest extends FunctionalTestCase
{
    private const COMMAND = 'setono:sylius-shipmondo:upload-orders';

    protected function setUp(): void
    {
        parent::setUp();

        // so the upload run's last step only fetches them
        $this->theWebhooksAreRegistered();
    }

    /**
     * Shipmondo lists a sales order it just created only after a short delay. The wait counts from when the order
     * became unlinked, so a later change to the order, e.g. a payment, doesn't put its lookup off
     *
     * @test
     */
    public function it_looks_up_the_sales_orders_of_the_orders_unlinked_for_at_least_ten_minutes(): void
    {
        $customer = $this->fixtures->createCustomer();
        $due = $this->unlinkedOrder($customer, '-10 minutes');
        $this->updatedAt($due, '-1 minute');
        $notDue = $this->unlinkedOrder($customer, '-9 minutes');
        $this->updatedAt($notDue, '-1 day');

        $this->respondWithSalesOrders([1001 => (string) $due->getNumber()]);

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        $lookups = $this->shipmondo->requests('GET', 'sales_orders');
        self::assertCount(1, $lookups);
        // no archived filter, so an archived sales order is found too
        parse_str($lookups[0]->getUri()->getQuery(), $query);
        self::assertSame(['order_id' => $due->getNumber(), 'page' => '1', 'per_page' => '25'], $query);

        $dueRow = $this->orderRow(self::id($due));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$dueRow['shipmondo_state'], $dueRow['shipmondo_id']]);
        $notDueRow = $this->orderRow(self::id($notDue));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UNLINKED, null], [$notDueRow['shipmondo_state'], $notDueRow['shipmondo_id']]);
    }

    /**
     * An order whose Shipmondo state hasn't changed since the plugin records when it does, has been unlinked for longer
     *
     * @test
     */
    public function it_looks_up_the_sales_order_of_an_order_unlinked_before_the_time_was_recorded(): void
    {
        $order = $this->unlinkedOrder($this->fixtures->createCustomer());
        $this->becameUnlinkedBeforeTheTimeWasRecorded($order);
        $this->updatedAt($order, '-1 minute');
        $this->respondWithSalesOrders([1001 => (string) $order->getNumber()]);

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        self::assertSame(1001, $this->orderRow(self::id($order))['shipmondo_id']);
    }

    /**
     * Orders that stay unlinked, e.g. with several sales orders, are looked up in every run, so the most recently
     * unlinked go first: when a rate limit stops the lookups, the older ones can't hold them back
     *
     * @test
     */
    public function it_looks_up_the_most_recently_unlinked_orders_first(): void
    {
        $customer = $this->fixtures->createCustomer();
        // one time for both, so the one created last, with the higher id, goes first
        $twentyMinutesAgo = (new \DateTimeImmutable('-20 minutes'))->format('Y-m-d H:i:s');
        $unlinkedLongest = $this->unlinkedOrder($customer, '-30 minutes');
        $this->updatedAt($unlinkedLongest, '-1 minute');
        $unlinkedRecently = $this->unlinkedOrder($customer, $twentyMinutesAgo);
        $this->updatedAt($unlinkedRecently, '-1 day');
        $unlinkedRecentlyAndCreatedLast = $this->unlinkedOrder($customer, $twentyMinutesAgo);
        $this->updatedAt($unlinkedRecentlyAndCreatedLast, '-2 days');

        // two sales orders for each, so each stays unlinked
        $salesOrders = [];
        foreach ([$unlinkedLongest, $unlinkedRecently, $unlinkedRecentlyAndCreatedLast] as $i => $order) {
            $salesOrders[1001 + 2 * $i] = (string) $order->getNumber();
            $salesOrders[1002 + 2 * $i] = (string) $order->getNumber();
        }
        $this->respondWithSalesOrders($salesOrders);

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        self::assertSame(
            [$unlinkedRecentlyAndCreatedLast->getNumber(), $unlinkedRecently->getNumber(), $unlinkedLongest->getNumber()],
            array_map(static function (RequestInterface $request): mixed {
                parse_str($request->getUri()->getQuery(), $query);

                return $query['order_id'] ?? null;
            }, $this->shipmondo->requests('GET', 'sales_orders')),
        );
    }

    /**
     * The order's sales order was deleted in Shipmondo, or the upload's call that failed temporarily didn't create one,
     * so it is uploaded again, in the same run as the pending orders
     *
     * @test
     */
    public function it_looks_up_the_sales_orders_and_then_uploads_the_pending_orders_and_the_orders_without_one(): void
    {
        $customer = $this->fixtures->createCustomer();
        $unlinked = $this->unlinkedOrder($customer);
        $pending = $this->fixtures->createOrder($customer);

        $this->respondWithSalesOrders([]);
        $this->respondWithThePaymentGateway();
        $this->shipmondo
            ->respond('POST', 'sales_orders', 201, ['id' => 1001])
            ->respond('POST', 'sales_orders', 201, ['id' => 1002])
        ;

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        self::assertSame(
            ['GET sales_orders', 'GET payment_gateways', 'POST sales_orders', 'POST sales_orders', 'GET webhooks'],
            $this->shipmondo->requestLines(),
        );
        $uploaded = array_map(
            static fn (RequestInterface $request): mixed => ShipmondoApiStub::jsonBody($request)['order_id'],
            $this->shipmondo->requests('POST', 'sales_orders'),
        );
        sort($uploaded);
        self::assertSame([$unlinked->getNumber(), $pending->getNumber()], $uploaded);

        foreach ([$unlinked, $pending] as $order) {
            $row = $this->orderRow(self::id($order));
            self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $row['shipmondo_state']);
            self::assertNotNull($row['shipmondo_id']);
        }
    }

    /**
     * Shipmondo has no idempotency key, and may have created the sales order before the call failed. Creating it again
     * could make a duplicate, which could be shipped twice
     *
     * @test
     */
    public function it_links_an_order_whose_upload_failed_temporarily_to_the_sales_order_shipmondo_created_instead_of_uploading_it_again(): void
    {
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer());
        $this->respondWithThePaymentGateway();
        $this->shipmondo->failToConnect('POST', 'sales_orders');

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        $row = $this->orderRow(self::id($order));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UNLINKED, null], [$row['shipmondo_state'], $row['shipmondo_id']]);

        // Shipmondo may not list the sales order it just created yet, so the next run doesn't look it up
        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        self::assertSame([], $this->shipmondo->requests('GET', 'sales_orders'));

        // ten minutes later, Shipmondo lists it
        $this->becameUnlinked($order, '-10 minutes');
        $this->respondWithSalesOrders([1001 => (string) $order->getNumber()]);

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        $row = $this->orderRow(self::id($order));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$row['shipmondo_state'], $row['shipmondo_id']]);
        self::assertCount(1, $this->shipmondo->requests('POST', 'sales_orders'));
    }

    /**
     * The cancellation left the order to its sales order lookup, since which sales order Shipmondo may have for it was
     * unknown. The cancel doesn't put the lookup off: it is due ten minutes after the order became unlinked
     *
     * @test
     *
     * @dataProvider lookupsOfACancelledOrder
     *
     * @param \Closure(self, string): mixed $respond
     */
    public function it_settles_an_unlinked_order_that_is_cancelled_in_sylius(\Closure $respond, string $state): void
    {
        $order = $this->unlinkedOrder($this->fixtures->createCustomer(), '-10 minutes');
        $this->cancel($order);
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $this->updatedAtOf($order), 'The cancel changed the order just now');
        $respond($this, (string) $order->getNumber());

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        $row = $this->orderRow(self::id($order));
        self::assertSame([OrderInterface::STATE_CANCELLED, $state, null], [$row['state'], $row['shipmondo_state'], $row['shipmondo_id']]);
        self::assertSame([], $this->shipmondo->requests('POST', 'sales_orders'));
    }

    /**
     * @return iterable<string, array{\Closure(self, string): mixed, string}>
     */
    public static function lookupsOfACancelledOrder(): iterable
    {
        yield 'one sales order: deleted, then cancelled' => [
            static function (self $test, string $orderId): void {
                $test->respondWithSalesOrders([1001 => $orderId]);
                $test->shipmondo->respond('DELETE', 'sales_orders/1001', 204, null);
            },
            OrderInterface::SHIPMONDO_STATE_CANCELLED,
        ];
        yield 'no sales order: cancelled' => [
            static function (self $test): void {
                $test->respondWithSalesOrders([]);
            },
            OrderInterface::SHIPMONDO_STATE_CANCELLED,
        ];
        yield 'several sales orders: stays unlinked' => [
            static function (self $test, string $orderId): void {
                $test->respondWithSalesOrders([1001 => $orderId, 1002 => $orderId]);
            },
            OrderInterface::SHIPMONDO_STATE_UNLINKED,
        ];
    }

    /**
     * The lookups have their own rate limit, so orders that stay unlinked, e.g. with several sales orders, can't keep
     * the pending orders from being uploaded. The command still fails, so cron monitoring notices
     *
     * @test
     */
    public function it_uploads_the_pending_orders_but_fails_when_shipmondo_rate_limits_a_lookup(): void
    {
        $customer = $this->fixtures->createCustomer();
        $unlinked = $this->unlinkedOrder($customer);
        $pending = $this->fixtures->createOrder($customer);

        $this->shipmondo->respond('GET', 'sales_orders', 429, ['error' => 'Too Many Requests']);
        $this->respondWithThePaymentGateway();
        $this->shipmondo->respond('POST', 'sales_orders', 201, ['id' => 1002]);

        $tester = $this->executeCommand(self::COMMAND);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            ['The sales order lookups stopped, because Shipmondo rate limited one. If it keeps happening, check the unlinked orders in Shipmondo: an order with several sales orders is looked up in every run. The pending orders were uploaded, and the remaining unlinked orders wait for the next run.'],
            CommandErrors::of($tester),
        );
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UNLINKED, $this->orderRow(self::id($unlinked))['shipmondo_state']);
        self::assertSame(1002, $this->orderRow(self::id($pending))['shipmondo_id']);
    }

    /**
     * Each lookup would fail the same way, e.g. after a change to Shipmondo's answers the SDK can't read, and leave its
     * order unlinked for good. The uploads may work, so the pending orders are still uploaded
     *
     * @test
     */
    public function it_stops_the_lookups_but_uploads_the_pending_orders_after_three_lookups_in_a_row_that_fail(): void
    {
        $customer = $this->fixtures->createCustomer();
        $unlinked = [];
        foreach (range(1, 4) as $i) {
            $unlinked[] = $this->unlinkedOrder($customer);
        }
        $pending = $this->fixtures->createOrder($customer);

        $this->shipmondo->respond('GET', 'sales_orders', 422, ['error' => 'Unknown filter order_id']);
        $this->respondWithThePaymentGateway();
        $this->shipmondo->respond('POST', 'sales_orders', 201, ['id' => 1005]);

        $tester = $this->executeCommand(self::COMMAND);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            ['The sales order lookups stopped after three lookups in a row that failed: Shipmondo rejected them, or its answers could not be read. Check the log and the version of the Shipmondo SDK. The pending orders were uploaded, and the remaining unlinked orders wait for the next run.'],
            CommandErrors::of($tester),
        );
        self::assertCount(3, $this->shipmondo->requests('GET', 'sales_orders'));
        foreach ($unlinked as $order) {
            self::assertSame(OrderInterface::SHIPMONDO_STATE_UNLINKED, $this->orderRow(self::id($order))['shipmondo_state']);
        }
        self::assertSame(1005, $this->orderRow(self::id($pending))['shipmondo_id']);
    }

    /**
     * Shipmondo is most likely down, so the uploads would wait out the time limit too
     *
     * @test
     */
    public function it_stops_the_upload_run_before_any_upload_after_three_lookups_in_a_row_that_fail_temporarily(): void
    {
        $customer = $this->fixtures->createCustomer();
        foreach (range(1, 3) as $i) {
            $this->unlinkedOrder($customer);
        }
        $pending = $this->fixtures->createOrder($customer);

        $this->shipmondo->failToConnect('GET', 'sales_orders');

        $tester = $this->executeCommand(self::COMMAND);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            ['The upload run stopped after three temporary failures in a row: Shipmondo answered with a server error, or not in time. Check whether Shipmondo is down, and the log. The remaining orders wait for the next run.'],
            CommandErrors::of($tester),
        );
        // the run stopped, so it doesn't register the webhooks again either
        self::assertSame(['GET sales_orders', 'GET sales_orders', 'GET sales_orders'], $this->shipmondo->requestLines());
        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $this->orderRow(self::id($pending))['shipmondo_state']);
    }

    /**
     * A shop narrows the orders that qualify for upload with the event, not the ones whose sales order may exist
     *
     * @test
     */
    public function it_looks_up_the_sales_orders_of_unlinked_orders_the_shop_keeps_from_being_uploaded(): void
    {
        $order = $this->unlinkedOrder($this->fixtures->createCustomer());
        $this->eventDispatcher()->addListener(PreQualifiedUploadableOrdersQueryBuilderCreatedEvent::class, static function (PreQualifiedUploadableOrdersQueryBuilderCreatedEvent $event): void {
            $event->queryBuilder->andWhere('1 = 0');
        });
        $this->respondWithSalesOrders([1001 => (string) $order->getNumber()]);

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        self::assertSame(1001, $this->orderRow(self::id($order))['shipmondo_id']);
    }

    /**
     * Answers the sales order lookups with these sales orders, by their id and order id
     *
     * @param array<int, string> $orderIds
     */
    private function respondWithSalesOrders(array $orderIds): void
    {
        $this->shipmondo->respond('GET', 'sales_orders', 200, SalesOrders::withOrderIds($orderIds), ['X-Total-Pages' => '1']);
    }

    /**
     * A placed and paid order whose upload made it unlinked at the given time, e.g. '-10 minutes'
     */
    private function unlinkedOrder(CustomerInterface $customer, string $since = '-10 minutes'): OrderInterface
    {
        $order = $this->fixtures->createOrder($customer);
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UNLINKED);
        $this->entityManager()->flush();

        $this->becameUnlinked($order, $since);

        return $order;
    }

    /**
     * Moves the time the order's Shipmondo state last changed, which tells how long it has been unlinked
     */
    private function becameUnlinked(OrderInterface $order, string $time): void
    {
        $this->writeTime($order, 'shipmondoStateChangedAt', $time);
    }

    /**
     * Clears the time the order's Shipmondo state last changed, as for an order whose Shipmondo state hasn't changed
     * since the plugin records that time
     */
    private function becameUnlinkedBeforeTheTimeWasRecorded(OrderInterface $order): void
    {
        $this->writeTime($order, 'shipmondoStateChangedAt', null);
    }

    /**
     * Moves the time the order was last changed, which doesn't tell how long it has been unlinked
     */
    private function updatedAt(OrderInterface $order, string $time): void
    {
        $this->writeTime($order, 'updated_at', $time);
    }

    private function updatedAtOf(OrderInterface $order): \DateTimeImmutable
    {
        $updatedAt = $this->database()->fetchOne('SELECT updated_at FROM sylius_order WHERE id = ?', [self::id($order)]);
        self::assertIsString($updatedAt);

        return new \DateTimeImmutable($updatedAt);
    }

    private function writeTime(OrderInterface $order, string $column, ?string $time): void
    {
        $this->connection()->executeStatement(
            sprintf('UPDATE sylius_order SET %s = ? WHERE id = ?', $column),
            [null === $time ? null : (new \DateTimeImmutable($time))->format('Y-m-d H:i:s'), self::id($order)],
        );
        // an upload run detaches the orders it loaded
        if ($this->entityManager()->contains($order)) {
            $this->entityManager()->refresh($order);
        }
    }
}
