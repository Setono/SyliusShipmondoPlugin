<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Model;

use PHPUnit\Framework\TestCase;
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
}
