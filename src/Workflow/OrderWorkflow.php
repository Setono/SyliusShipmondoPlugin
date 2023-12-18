<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Workflow;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\Workflow\DefinitionBuilder;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\Workflow;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class OrderWorkflow
{
    private const PROPERTY_NAME = 'shipmondoState';

    public const NAME = 'setono_sylius_shipmondo__order';

    public const TRANSITION_DISPATCH = 'dispatch';

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
            OrderInterface::SHIPMONDO_STATE_DISPATCHING,
        ];
    }

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

    public static function getWorkflow(EventDispatcherInterface $eventDispatcher): Workflow
    {
        $definitionBuilder = new DefinitionBuilder(self::getStates(), self::getTransitions());

        return new Workflow(
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
            new Transition(self::TRANSITION_DISPATCH, [OrderInterface::SHIPMONDO_STATE_PENDING], OrderInterface::SHIPMONDO_STATE_DISPATCHING),
        ];
    }
}
