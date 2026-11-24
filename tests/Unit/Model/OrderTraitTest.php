<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Model;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Failure\RecordedShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

final class OrderTraitTest extends TestCase
{
    /**
     * @test
     */
    public function it_has_sensible_defaults(): void
    {
        $order = new Order();

        self::assertSame(1, $order->getVersion());
        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $order->getShipmondoState());
        self::assertNull($order->getShipmondoId());
        // a new order hasn't changed its Shipmondo state yet
        self::assertNull($order->getShipmondoStateChangedAt());
        self::assertNull($order->getShipmondoFailure());
    }

    /**
     * @test
     */
    public function it_records_the_shipmondo_failure_that_put_the_order_in_its_shipmondo_state(): void
    {
        $order = new Order();

        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_FAILED, [
            OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE => new RecordedShipmondoFailure(ShipmondoFailureKind::Rejected, 422, 'The payment gateway does not exist'),
        ]);

        self::assertSame(OrderInterface::SHIPMONDO_STATE_FAILED, $order->getShipmondoState());
        self::assertEquals(new RecordedShipmondoFailure(ShipmondoFailureKind::Rejected, 422, 'The payment gateway does not exist'), $order->getShipmondoFailure());
    }

    /**
     * The Shipmondo state owns the failure, so it never outlives the state it explains
     *
     * @test
     *
     * @dataProvider contextsWithoutAShipmondoFailure
     *
     * @param array<array-key, mixed> $context
     */
    public function it_clears_the_shipmondo_failure_when_the_shipmondo_state_changes_without_one(array $context): void
    {
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_FAILED, [
            OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE => new RecordedShipmondoFailure(ShipmondoFailureKind::Rejected, 422, 'Invalid'),
        ]);

        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_PENDING, $context);

        self::assertNull($order->getShipmondoFailure());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function contextsWithoutAShipmondoFailure(): iterable
    {
        yield 'no context' => [[]];
        yield 'no Shipmondo failure' => [[OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE => null]];
    }

    /**
     * @test
     */
    public function it_replaces_the_shipmondo_failure_with_the_one_that_put_the_order_in_its_new_shipmondo_state(): void
    {
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_PENDING, [
            OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE => new RecordedShipmondoFailure(ShipmondoFailureKind::RateLimited, 429, 'Too many requests'),
        ]);

        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UNLINKED, [
            OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE => new RecordedShipmondoFailure(ShipmondoFailureKind::TemporaryFailure, null, 'Idle timeout reached'),
        ]);

        self::assertEquals(new RecordedShipmondoFailure(ShipmondoFailureKind::TemporaryFailure, null, 'Idle timeout reached'), $order->getShipmondoFailure());
    }

    /**
     * @test
     */
    public function it_sets_the_version(): void
    {
        $order = new Order();

        $order->setVersion(7);
        self::assertSame(7, $order->getVersion());

        $order->setVersion(null);
        self::assertSame(0, $order->getVersion());
    }

    /**
     * @test
     */
    public function it_links_an_order_that_becomes_uploaded_to_the_sales_order_in_the_context(): void
    {
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 12345]);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 12345], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @test
     *
     * @dataProvider statesWithoutASalesOrder
     */
    public function it_unlinks_an_order_that_enters_any_other_state(string $state): void
    {
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 12345]);

        // only an uploaded order is linked, so a sales order id in the context changes nothing
        $order->setShipmondoState($state, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 67890]);

        self::assertSame([$state, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesWithoutASalesOrder(): iterable
    {
        yield 'pending' => [OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'uploading' => [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO];
        yield 'failed' => [OrderInterface::SHIPMONDO_STATE_FAILED];
        yield 'cancelled' => [OrderInterface::SHIPMONDO_STATE_CANCELLED];
    }

    /**
     * @test
     *
     * @dataProvider contextsWithoutASalesOrderId
     *
     * @param array<array-key, mixed> $context
     */
    public function it_refuses_to_make_an_order_uploaded_without_a_sales_order_to_link_it_to(array $context): void
    {
        $order = new Order();

        try {
            $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $context);
            self::fail('An order became uploaded without a sales order');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('An uploaded order is linked to a sales order, so the order workflow\'s context must hold the sales order id under "sales_order_id"', $e->getMessage());
        }

        self::assertSame([OrderInterface::SHIPMONDO_STATE_PENDING, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertNull($order->getShipmondoStateChangedAt(), 'A refused change of the Shipmondo state is no change');
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function contextsWithoutASalesOrderId(): iterable
    {
        yield 'no context' => [[]];
        yield 'no sales order' => [[OrderWorkflow::CONTEXT_SALES_ORDER_ID => null]];
        yield 'not an id' => [[OrderWorkflow::CONTEXT_SALES_ORDER_ID => '12345']];
    }

    /**
     * E.g. the failure itself instead of what the order records of it
     *
     * @test
     */
    public function it_refuses_a_shipmondo_failure_it_cannot_record(): void
    {
        $order = new Order();

        try {
            $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_FAILED, [OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE => 'rejected']);
            self::fail('The order took a Shipmondo failure it cannot record');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(sprintf('The order workflow\'s context must hold a %s under "shipmondo_failure", or nothing', RecordedShipmondoFailure::class), $e->getMessage());
        }

        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $order->getShipmondoState());
        self::assertNull($order->getShipmondoStateChangedAt(), 'A refused change of the Shipmondo state is no change');
    }

    /**
     * E.g. after a downgrade of the plugin, so the order page still shows the order
     *
     * @test
     */
    public function it_reads_a_shipmondo_failure_of_a_kind_it_does_not_know_as_none(): void
    {
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_FAILED, [
            OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE => new RecordedShipmondoFailure(ShipmondoFailureKind::Rejected, 422, 'Invalid'),
        ]);
        // as the database could hold it
        (new \ReflectionProperty($order, 'shipmondoFailureKind'))->setValue($order, 'conflict');

        self::assertNull($order->getShipmondoFailure());
    }
}
