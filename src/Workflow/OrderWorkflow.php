<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Workflow;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

final class OrderWorkflow
{
    private const PROPERTY_NAME = 'shipmondoState';

    final public const NAME = 'setono_sylius_shipmondo__order';

    final public const TRANSITION_START_UPLOAD = 'start_upload';

    final public const TRANSITION_COMPLETE_UPLOAD = 'complete_upload';

    final public const TRANSITION_FAIL = 'fail';

    final public const TRANSITION_RESET = 'reset';

    final public const TRANSITION_RETRY = 'retry';

    final public const TRANSITION_CANCEL = 'cancel';

    /**
     * The key of the sales order id in the context of a transition into uploaded, i.e. complete_upload
     */
    final public const CONTEXT_SALES_ORDER_ID = 'sales_order_id';

    private function __construct()
    {
    }

    /**
     * @return array<array-key, string>
     */
    public static function getStates(): array
    {
        return [
            OrderInterface::SHIPMONDO_STATE_PENDING,
            OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO,
            OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO,
            OrderInterface::SHIPMONDO_STATE_FAILED,
            OrderInterface::SHIPMONDO_STATE_CANCELLED,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        $transitions = [];
        foreach (self::getTransitions() as $transition) {
            $transitions[$transition->getName()] = [
                'from' => $transition->getFroms(),
                'to' => $transition->getTos(),
            ];
        }

        return [
            self::NAME => [
                'type' => 'state_machine',
                'marking_store' => [
                    'type' => 'method',
                    'property' => self::PROPERTY_NAME,
                ],
                'supports' => OrderInterface::class,
                'initial_marking' => OrderInterface::SHIPMONDO_STATE_PENDING,
                'places' => self::getStates(),
                'transitions' => $transitions,
            ],
        ];
    }

    /**
     * Builds the state machine the container registers from {@see self::getConfig()}, without a container: like
     * FrameworkBundle does for a state machine, a transition with several "from" places becomes one transition per
     * place, so it is enabled from any of them. The {@see OrderCancellationGuard} the container registers is added to
     * the event dispatcher
     */
    public static function getWorkflow(EventDispatcherInterface $eventDispatcher): StateMachine
    {
        $eventDispatcher->addSubscriber(new OrderCancellationGuard());

        // the first place, pending, is the initial place
        $definitionBuilder = new DefinitionBuilder(self::getStates());

        foreach (self::getTransitions() as $transition) {
            foreach ($transition->getFroms() as $from) {
                $definitionBuilder->addTransition(new Transition($transition->getName(), $from, $transition->getTos()));
            }
        }

        return new StateMachine(
            $definitionBuilder->build(),
            new MethodMarkingStore(true, self::PROPERTY_NAME),
            $eventDispatcher,
            self::NAME,
        );
    }

    /**
     * @return array<array-key, Transition>
     */
    public static function getTransitions(): array
    {
        return [
            new Transition(self::TRANSITION_START_UPLOAD, [OrderInterface::SHIPMONDO_STATE_PENDING], OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO),
            new Transition(self::TRANSITION_COMPLETE_UPLOAD, [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO),
            new Transition(self::TRANSITION_FAIL, [OrderInterface::SHIPMONDO_STATE_PENDING, OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], OrderInterface::SHIPMONDO_STATE_FAILED),
            // when the sales order of an order that isn't cancelled is deleted, the order goes back to pending to be uploaded again
            new Transition(self::TRANSITION_RESET, [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO], OrderInterface::SHIPMONDO_STATE_PENDING),
            // when an upload fails for a reason that says nothing about the order, the next upload run tries again
            new Transition(self::TRANSITION_RETRY, [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], OrderInterface::SHIPMONDO_STATE_PENDING),
            // when the order is cancelled in Sylius and Shipmondo has no sales order for it (anymore). An uploading order
            // is cancelled by its upload, once it is pending, failed or uploaded again
            new Transition(self::TRANSITION_CANCEL, [OrderInterface::SHIPMONDO_STATE_PENDING, OrderInterface::SHIPMONDO_STATE_FAILED, OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO], OrderInterface::SHIPMONDO_STATE_CANCELLED),
        ];
    }
}
