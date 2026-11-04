<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Workflow;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderCancellationGuard;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\TransitionBlocker;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;

/**
 * Exercises the guard through the real order state machine, which registers it
 */
final class OrderCancellationGuardTest extends TestCase
{
    private StateMachine $workflow;

    protected function setUp(): void
    {
        $this->workflow = OrderWorkflow::getWorkflow(new EventDispatcher());
    }

    /**
     * @test
     */
    public function it_guards_the_cancel_and_reset_transitions_of_the_order_workflow(): void
    {
        self::assertSame([
            'workflow.setono_sylius_shipmondo__order.guard.cancel' => 'guardCancel',
            'workflow.setono_sylius_shipmondo__order.guard.reset' => 'guardReset',
        ], OrderCancellationGuard::getSubscribedEvents());
    }

    /**
     * @test
     *
     * @dataProvider statesACancelledOrderIsCancelledFrom
     */
    public function it_lets_an_order_that_is_cancelled_in_sylius_be_cancelled(string $state): void
    {
        $order = self::order(OrderInterface::STATE_CANCELLED, $state);

        self::assertTrue($this->workflow->can($order, OrderWorkflow::TRANSITION_CANCEL));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesACancelledOrderIsCancelledFrom(): iterable
    {
        yield 'pending' => [OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'failed' => [OrderInterface::SHIPMONDO_STATE_FAILED];
        yield 'uploaded' => [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO];
    }

    /**
     * @test
     */
    public function it_keeps_an_order_that_is_not_cancelled_in_sylius_from_being_cancelled(): void
    {
        $order = self::order(OrderInterface::STATE_NEW, OrderInterface::SHIPMONDO_STATE_PENDING);

        self::assertSame(
            ['Only an order that is cancelled in Sylius can be cancelled in Shipmondo'],
            $this->blockers($order, OrderWorkflow::TRANSITION_CANCEL),
        );
    }

    /**
     * @test
     */
    public function it_never_resets_a_cancelled_order_to_be_uploaded_again(): void
    {
        $order = self::order(OrderInterface::STATE_CANCELLED, OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO);

        self::assertSame(
            ['A cancelled order is cancelled in Shipmondo, not reset to be uploaded again'],
            $this->blockers($order, OrderWorkflow::TRANSITION_RESET),
        );
    }

    /**
     * @test
     */
    public function it_resets_an_order_that_is_not_cancelled(): void
    {
        $order = self::order(OrderInterface::STATE_NEW, OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO);

        self::assertTrue($this->workflow->can($order, OrderWorkflow::TRANSITION_RESET));
    }

    /**
     * @test
     */
    public function it_treats_a_subject_that_is_not_an_order_as_not_cancelled(): void
    {
        $guard = new OrderCancellationGuard();

        $cancel = new GuardEvent(new \stdClass(), new Marking(), new Transition(OrderWorkflow::TRANSITION_CANCEL, 'pending', 'cancelled'));
        $guard->guardCancel($cancel);
        self::assertTrue($cancel->isBlocked());

        $reset = new GuardEvent(new \stdClass(), new Marking(), new Transition(OrderWorkflow::TRANSITION_RESET, 'uploaded_to_shipmondo', 'pending'));
        $guard->guardReset($reset);
        self::assertFalse($reset->isBlocked());
    }

    /**
     * @return list<string>
     */
    private function blockers(Order $order, string $transition): array
    {
        return array_map(
            static fn (TransitionBlocker $blocker): string => $blocker->getMessage(),
            iterator_to_array($this->workflow->buildTransitionBlockerList($order, $transition), false),
        );
    }

    private static function order(string $state, string $shipmondoState): Order
    {
        $order = new Order();
        $order->setState($state);
        $order->setShipmondoState($shipmondoState, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => 1001]);

        return $order;
    }
}
