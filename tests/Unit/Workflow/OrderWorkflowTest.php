<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Workflow\Transition;

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

        self::assertCount(4, $transitions);
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
                ],
            ],
        ], $config);
    }
}
