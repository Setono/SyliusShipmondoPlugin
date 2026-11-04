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
use Tests\Setono\SyliusShipmondoPlugin\Unit\Orders;

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
    public function it_guards_the_cancel_transition_of_the_order_workflow(): void
    {
        self::assertSame([
            'workflow.setono_sylius_shipmondo__order.guard.cancel' => 'guardCancel',
        ], OrderCancellationGuard::getSubscribedEvents());
    }

    /**
     * @test
     *
     * @dataProvider statesWithoutASalesOrder
     */
    public function it_lets_an_order_that_is_cancelled_in_sylius_become_cancelled(string $shipmondoState): void
    {
        $order = Orders::create($shipmondoState, state: OrderInterface::STATE_CANCELLED);

        self::assertTrue($this->workflow->can($order, OrderWorkflow::TRANSITION_CANCEL));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesWithoutASalesOrder(): iterable
    {
        yield 'pending' => [OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'failed' => [OrderInterface::SHIPMONDO_STATE_FAILED];
    }

    /**
     * @test
     */
    public function it_keeps_an_order_that_is_not_cancelled_in_sylius_from_becoming_cancelled(): void
    {
        $order = Orders::create();

        self::assertSame(['The order is not cancelled in Sylius'], $this->blockers($order, OrderWorkflow::TRANSITION_CANCEL));
    }

    /**
     * @test
     */
    public function it_never_lets_an_order_with_a_sales_order_become_cancelled(): void
    {
        // the order is reset to pending once its sales order is deleted, and becomes cancelled from there
        $order = Orders::create(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001, OrderInterface::STATE_CANCELLED);

        self::assertFalse($this->workflow->can($order, OrderWorkflow::TRANSITION_CANCEL));
        self::assertTrue($this->workflow->can($order, OrderWorkflow::TRANSITION_RESET));
    }

    /**
     * @test
     */
    public function it_treats_a_subject_that_is_not_an_order_as_not_cancelled(): void
    {
        $event = new GuardEvent(new \stdClass(), new Marking(), new Transition(OrderWorkflow::TRANSITION_CANCEL, 'pending', 'cancelled'));

        (new OrderCancellationGuard())->guardCancel($event);

        self::assertTrue($event->isBlocked());
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
}
