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

/**
 * The sales order lookups an upload run makes before it uploads the pending orders, driven through the upload command
 * against the database
 */
final class SalesOrderLookupTest extends FunctionalTestCase
{
    private const COMMAND = 'setono:sylius-shipmondo:upload-orders';

    /**
     * Shipmondo lists a sales order it just created only after a short delay
     *
     * @test
     */
    public function it_looks_up_the_sales_orders_of_the_orders_unlinked_for_at_least_ten_minutes(): void
    {
        $customer = $this->fixtures->createCustomer();
        $due = $this->unlinkedOrder($customer, '-10 minutes');
        $notDue = $this->unlinkedOrder($customer, '-9 minutes');

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
            ['GET sales_orders', 'GET payment_gateways', 'POST sales_orders', 'POST sales_orders'],
            array_map(static fn (RequestInterface $request): string => $request->getMethod() . ' ' . str_replace('/api/public/v3/', '', $request->getUri()->getPath()), $this->shipmondo->requests()),
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

        // ten minutes later, Shipmondo lists the sales order it created
        $this->updatedAt($order, '-10 minutes');
        $this->respondWithSalesOrders([1001 => (string) $order->getNumber()]);

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        $row = $this->orderRow(self::id($order));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$row['shipmondo_state'], $row['shipmondo_id']]);
        self::assertCount(1, $this->shipmondo->requests('POST', 'sales_orders'));
    }

    /**
     * The cancellation left the order to its sales order lookup, since which sales order Shipmondo may have for it was
     * unknown
     *
     * @test
     *
     * @dataProvider lookupsOfACancelledOrder
     *
     * @param \Closure(self, string): mixed $respond
     */
    public function it_settles_an_unlinked_order_that_is_cancelled_in_sylius(\Closure $respond, string $state): void
    {
        $order = $this->unlinkedOrder($this->fixtures->createCustomer());
        $this->cancel($order);
        $this->updatedAt($order, '-10 minutes');
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
        self::assertStringContainsString(
            'Shipmondo rate limited the upload run, so it did not get through every order. The remaining orders wait for the next run.',
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay()),
        );
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UNLINKED, $this->orderRow(self::id($unlinked))['shipmondo_state']);
        self::assertSame(1002, $this->orderRow(self::id($pending))['shipmondo_id']);
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
        $salesOrders = [];
        foreach ($orderIds as $id => $orderId) {
            $salesOrders[] = ['id' => $id, 'order_id' => $orderId, 'source_name' => 'Sylius'];
        }

        $this->shipmondo->respond('GET', 'sales_orders', 200, $salesOrders, ['X-Total-Pages' => '1']);
    }

    /**
     * A placed and paid order whose upload made it unlinked, the given time ago
     */
    private function unlinkedOrder(CustomerInterface $customer, string $since = '-10 minutes'): OrderInterface
    {
        $order = $this->fixtures->createOrder($customer);
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UNLINKED);
        $this->entityManager()->flush();

        $this->updatedAt($order, $since);

        return $order;
    }

    /**
     * Moves the time the order was last changed, which tells how long it has been unlinked
     */
    private function updatedAt(OrderInterface $order, string $modifier): void
    {
        $this->connection()->executeStatement(
            'UPDATE sylius_order SET updated_at = ? WHERE id = ?',
            [(new \DateTimeImmutable($modifier))->format('Y-m-d H:i:s'), self::id($order)],
        );
        // an upload run detaches the orders it loaded
        if ($this->entityManager()->contains($order)) {
            $this->entityManager()->refresh($order);
        }
    }
}
