<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Transition;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

final class OrderWorkflowTest extends TestCase
{
    /**
     * @test
     */
    public function it_returns_all_states(): void
    {
        self::assertSame([
            OrderInterface::SHIPMONDO_STATE_PENDING,
            OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO,
            OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO,
            OrderInterface::SHIPMONDO_STATE_UNLINKED,
            OrderInterface::SHIPMONDO_STATE_FAILED,
            OrderInterface::SHIPMONDO_STATE_CANCELLED,
        ], OrderWorkflow::getStates());
    }

    /**
     * @test
     */
    public function it_returns_the_transitions(): void
    {
        $transitions = OrderWorkflow::getTransitions();

        self::assertCount(8, $transitions);
        self::assertContainsOnlyInstancesOf(Transition::class, $transitions);

        $byName = [];
        foreach ($transitions as $transition) {
            $byName[$transition->getName()] = $transition;
        }

        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_PENDING],
            $byName[OrderWorkflow::TRANSITION_START_UPLOAD]->getFroms(),
        );
        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
            $byName[OrderWorkflow::TRANSITION_START_UPLOAD]->getTos(),
        );

        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
            $byName[OrderWorkflow::TRANSITION_COMPLETE_UPLOAD]->getFroms(),
        );
        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO],
            $byName[OrderWorkflow::TRANSITION_COMPLETE_UPLOAD]->getTos(),
        );

        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
            $byName[OrderWorkflow::TRANSITION_COMPLETE_UPLOAD_UNLINKED]->getFroms(),
        );
        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_UNLINKED],
            $byName[OrderWorkflow::TRANSITION_COMPLETE_UPLOAD_UNLINKED]->getTos(),
        );

        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_UNLINKED],
            $byName[OrderWorkflow::TRANSITION_LINK]->getFroms(),
        );
        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO],
            $byName[OrderWorkflow::TRANSITION_LINK]->getTos(),
        );

        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_PENDING, OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
            $byName[OrderWorkflow::TRANSITION_FAIL]->getFroms(),
        );
        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_FAILED],
            $byName[OrderWorkflow::TRANSITION_FAIL]->getTos(),
        );

        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO],
            $byName[OrderWorkflow::TRANSITION_RESET]->getFroms(),
        );
        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_PENDING],
            $byName[OrderWorkflow::TRANSITION_RESET]->getTos(),
        );

        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO, OrderInterface::SHIPMONDO_STATE_FAILED, OrderInterface::SHIPMONDO_STATE_UNLINKED],
            $byName[OrderWorkflow::TRANSITION_RETRY]->getFroms(),
        );
        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_PENDING],
            $byName[OrderWorkflow::TRANSITION_RETRY]->getTos(),
        );

        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_PENDING, OrderInterface::SHIPMONDO_STATE_FAILED],
            $byName[OrderWorkflow::TRANSITION_CANCEL]->getFroms(),
        );
        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_CANCELLED],
            $byName[OrderWorkflow::TRANSITION_CANCEL]->getTos(),
        );
    }

    /**
     * @test
     */
    public function it_builds_a_workflow_that_links_an_order_to_the_sales_order_it_was_uploaded_as(): void
    {
        $workflow = OrderWorkflow::getWorkflow(new EventDispatcher());
        $order = new Order();
        $workflow->apply($order, OrderWorkflow::TRANSITION_START_UPLOAD);

        $workflow->apply($order, OrderWorkflow::TRANSITION_COMPLETE_UPLOAD, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 1001]);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$order->getShipmondoState(), $order->getShipmondoId()]);

        $workflow->apply($order, OrderWorkflow::TRANSITION_RESET);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_PENDING, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * Shipmondo may have a sales order for an unlinked order, so only a sales order lookup moves it out of unlinked:
     * linking it to the sales order it finds, or retrying it when it finds none. Cancelling it isn't enabled, since
     * its sales order may still exist
     *
     * @test
     */
    public function it_builds_a_workflow_that_makes_an_order_unlinked_and_lets_only_a_sales_order_lookup_move_it_out_of_unlinked(): void
    {
        $workflow = OrderWorkflow::getWorkflow(new EventDispatcher());
        $order = new Order();
        $order->setState(OrderInterface::STATE_CANCELLED);
        $workflow->apply($order, OrderWorkflow::TRANSITION_START_UPLOAD);

        $workflow->apply($order, OrderWorkflow::TRANSITION_COMPLETE_UPLOAD_UNLINKED);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_UNLINKED, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
        self::assertSame(
            [OrderWorkflow::TRANSITION_LINK, OrderWorkflow::TRANSITION_RETRY],
            array_map(static fn (Transition $transition): string => $transition->getName(), $workflow->getEnabledTransitions($order)),
        );
    }

    /**
     * @test
     */
    public function it_builds_a_workflow_that_links_an_unlinked_order_to_the_sales_order_a_lookup_found(): void
    {
        $workflow = OrderWorkflow::getWorkflow(new EventDispatcher());
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UNLINKED);

        $workflow->apply($order, OrderWorkflow::TRANSITION_LINK, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 1001]);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @test
     */
    public function it_builds_a_workflow_that_does_not_link_an_unlinked_order_without_a_sales_order(): void
    {
        $workflow = OrderWorkflow::getWorkflow(new EventDispatcher());
        $order = new Order();
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UNLINKED);

        $this->expectException(\InvalidArgumentException::class);

        $workflow->apply($order, OrderWorkflow::TRANSITION_LINK);
    }

    /**
     * An admin retries the upload of a failed order, the upload retries an order whose upload failed in a way that says
     * nothing about it, and a sales order lookup retries an unlinked order whose sales order it doesn't find: all put
     * the order back to pending, for the next upload run
     *
     * @test
     *
     * @dataProvider retriedStates
     */
    public function it_builds_a_workflow_that_retries_the_upload_of_a_failed_uploading_or_unlinked_order(string $state): void
    {
        $workflow = OrderWorkflow::getWorkflow(new EventDispatcher());
        $order = new Order();
        $order->setShipmondoState($state);

        self::assertTrue($workflow->can($order, OrderWorkflow::TRANSITION_RETRY));

        $workflow->apply($order, OrderWorkflow::TRANSITION_RETRY);

        self::assertSame([OrderInterface::SHIPMONDO_STATE_PENDING, null], [$order->getShipmondoState(), $order->getShipmondoId()]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function retriedStates(): iterable
    {
        yield 'failed' => [OrderInterface::SHIPMONDO_STATE_FAILED];
        yield 'uploading' => [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO];
        yield 'unlinked' => [OrderInterface::SHIPMONDO_STATE_UNLINKED];
    }

    /**
     * A pending order is waiting for its upload already, an uploaded order has a sales order, and a cancelled order is
     * done
     *
     * @test
     *
     * @dataProvider statesThatAreNotRetried
     */
    public function it_builds_a_workflow_that_does_not_retry_the_upload_of_an_order_in_any_other_state(string $state, ?int $salesOrderId): void
    {
        $workflow = OrderWorkflow::getWorkflow(new EventDispatcher());
        $order = new Order();
        $order->setShipmondoState($state, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => $salesOrderId]);

        self::assertFalse($workflow->can($order, OrderWorkflow::TRANSITION_RETRY));
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function statesThatAreNotRetried(): iterable
    {
        yield 'pending' => [OrderInterface::SHIPMONDO_STATE_PENDING, null];
        yield 'uploaded' => [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001];
        yield 'cancelled' => [OrderInterface::SHIPMONDO_STATE_CANCELLED, null];
    }

    /**
     * @test
     */
    public function it_builds_a_workflow_that_stores_the_state_as_a_string_on_the_order(): void
    {
        /** @var list<string> $dispatched */
        $dispatched = [];
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener('workflow.setono_sylius_shipmondo__order.completed.start_upload', static function () use (&$dispatched): void {
            $dispatched[] = 'completed.start_upload';
        });

        $workflow = OrderWorkflow::getWorkflow($eventDispatcher);

        self::assertSame(OrderWorkflow::NAME, $workflow->getName());
        self::assertSame(OrderWorkflow::getStates(), array_values($workflow->getDefinition()->getPlaces()));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_PENDING], $workflow->getDefinition()->getInitialPlaces());

        $order = new Order();
        self::assertTrue($workflow->can($order, OrderWorkflow::TRANSITION_START_UPLOAD));

        $workflow->apply($order, OrderWorkflow::TRANSITION_START_UPLOAD);

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO, $order->getShipmondoState());
        self::assertSame(['completed.start_upload'], $dispatched);
    }

    /**
     * @test
     */
    public function it_builds_the_state_machine_the_container_registers_so_a_transition_is_enabled_from_each_of_its_places(): void
    {
        $workflow = OrderWorkflow::getWorkflow(new EventDispatcher());

        $transitions = array_map(
            static fn (Transition $transition): array => [$transition->getName(), $transition->getFroms(), $transition->getTos()],
            $workflow->getDefinition()->getTransitions(),
        );

        self::assertSame([
            [OrderWorkflow::TRANSITION_START_UPLOAD, [OrderInterface::SHIPMONDO_STATE_PENDING], [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO]],
            [OrderWorkflow::TRANSITION_COMPLETE_UPLOAD, [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO]],
            [OrderWorkflow::TRANSITION_COMPLETE_UPLOAD_UNLINKED, [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], [OrderInterface::SHIPMONDO_STATE_UNLINKED]],
            [OrderWorkflow::TRANSITION_LINK, [OrderInterface::SHIPMONDO_STATE_UNLINKED], [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO]],
            [OrderWorkflow::TRANSITION_FAIL, [OrderInterface::SHIPMONDO_STATE_PENDING], [OrderInterface::SHIPMONDO_STATE_FAILED]],
            [OrderWorkflow::TRANSITION_FAIL, [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], [OrderInterface::SHIPMONDO_STATE_FAILED]],
            [OrderWorkflow::TRANSITION_RESET, [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO], [OrderInterface::SHIPMONDO_STATE_PENDING]],
            [OrderWorkflow::TRANSITION_RETRY, [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], [OrderInterface::SHIPMONDO_STATE_PENDING]],
            [OrderWorkflow::TRANSITION_RETRY, [OrderInterface::SHIPMONDO_STATE_FAILED], [OrderInterface::SHIPMONDO_STATE_PENDING]],
            [OrderWorkflow::TRANSITION_RETRY, [OrderInterface::SHIPMONDO_STATE_UNLINKED], [OrderInterface::SHIPMONDO_STATE_PENDING]],
            [OrderWorkflow::TRANSITION_CANCEL, [OrderInterface::SHIPMONDO_STATE_PENDING], [OrderInterface::SHIPMONDO_STATE_CANCELLED]],
            [OrderWorkflow::TRANSITION_CANCEL, [OrderInterface::SHIPMONDO_STATE_FAILED], [OrderInterface::SHIPMONDO_STATE_CANCELLED]],
        ], $transitions);

        foreach ([OrderInterface::SHIPMONDO_STATE_PENDING, OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO] as $state) {
            $order = new Order();
            $order->setShipmondoState($state);

            self::assertTrue($workflow->can($order, OrderWorkflow::TRANSITION_FAIL), sprintf('An order in "%s" can fail', $state));
        }
    }

    /**
     * @test
     */
    public function it_builds_the_workflow_config(): void
    {
        $config = OrderWorkflow::getConfig();

        self::assertSame([
            OrderWorkflow::NAME => [
                'type' => 'state_machine',
                'marking_store' => [
                    'type' => 'method',
                    'property' => 'shipmondoState',
                ],
                'supports' => OrderInterface::class,
                'initial_marking' => OrderInterface::SHIPMONDO_STATE_PENDING,
                'places' => [
                    OrderInterface::SHIPMONDO_STATE_PENDING,
                    OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO,
                    OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO,
                    OrderInterface::SHIPMONDO_STATE_UNLINKED,
                    OrderInterface::SHIPMONDO_STATE_FAILED,
                    OrderInterface::SHIPMONDO_STATE_CANCELLED,
                ],
                'transitions' => [
                    OrderWorkflow::TRANSITION_START_UPLOAD => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_PENDING],
                        'to' => [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
                    ],
                    OrderWorkflow::TRANSITION_COMPLETE_UPLOAD => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
                        'to' => [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO],
                    ],
                    OrderWorkflow::TRANSITION_COMPLETE_UPLOAD_UNLINKED => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
                        'to' => [OrderInterface::SHIPMONDO_STATE_UNLINKED],
                    ],
                    OrderWorkflow::TRANSITION_LINK => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_UNLINKED],
                        'to' => [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO],
                    ],
                    OrderWorkflow::TRANSITION_FAIL => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_PENDING, OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
                        'to' => [OrderInterface::SHIPMONDO_STATE_FAILED],
                    ],
                    OrderWorkflow::TRANSITION_RESET => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO],
                        'to' => [OrderInterface::SHIPMONDO_STATE_PENDING],
                    ],
                    OrderWorkflow::TRANSITION_RETRY => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO, OrderInterface::SHIPMONDO_STATE_FAILED, OrderInterface::SHIPMONDO_STATE_UNLINKED],
                        'to' => [OrderInterface::SHIPMONDO_STATE_PENDING],
                    ],
                    OrderWorkflow::TRANSITION_CANCEL => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_PENDING, OrderInterface::SHIPMONDO_STATE_FAILED],
                        'to' => [OrderInterface::SHIPMONDO_STATE_CANCELLED],
                    ],
                ],
            ],
        ], $config);
    }
}
