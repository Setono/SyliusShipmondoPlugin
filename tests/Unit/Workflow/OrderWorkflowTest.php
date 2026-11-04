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
            OrderInterface::SHIPMONDO_STATE_FAILED,
        ], OrderWorkflow::getStates());
    }

    /**
     * @test
     */
    public function it_returns_the_transitions(): void
    {
        $transitions = OrderWorkflow::getTransitions();

        self::assertCount(5, $transitions);
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
            [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
            $byName[OrderWorkflow::TRANSITION_RETRY]->getFroms(),
        );
        self::assertSame(
            [OrderInterface::SHIPMONDO_STATE_PENDING],
            $byName[OrderWorkflow::TRANSITION_RETRY]->getTos(),
        );
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
            [OrderWorkflow::TRANSITION_FAIL, [OrderInterface::SHIPMONDO_STATE_PENDING], [OrderInterface::SHIPMONDO_STATE_FAILED]],
            [OrderWorkflow::TRANSITION_FAIL, [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], [OrderInterface::SHIPMONDO_STATE_FAILED]],
            [OrderWorkflow::TRANSITION_RESET, [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO], [OrderInterface::SHIPMONDO_STATE_PENDING]],
            [OrderWorkflow::TRANSITION_RETRY, [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], [OrderInterface::SHIPMONDO_STATE_PENDING]],
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
                    OrderInterface::SHIPMONDO_STATE_FAILED,
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
                    OrderWorkflow::TRANSITION_FAIL => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_PENDING, OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
                        'to' => [OrderInterface::SHIPMONDO_STATE_FAILED],
                    ],
                    OrderWorkflow::TRANSITION_RESET => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO],
                        'to' => [OrderInterface::SHIPMONDO_STATE_PENDING],
                    ],
                    OrderWorkflow::TRANSITION_RETRY => [
                        'from' => [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO],
                        'to' => [OrderInterface::SHIPMONDO_STATE_PENDING],
                    ],
                ],
            ],
        ], $config);
    }
}
