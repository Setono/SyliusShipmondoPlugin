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

    final public const NAME = 'setono_sylius_shipmondo__order';

    final public const TRANSITION_START_UPLOAD = 'start_upload';

    final public const TRANSITION_COMPLETE_UPLOAD = 'complete_upload';

    final public const TRANSITION_FAIL = 'fail';

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
            new Transition(self::TRANSITION_START_UPLOAD, [OrderInterface::SHIPMONDO_STATE_PENDING], OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO),
            new Transition(self::TRANSITION_COMPLETE_UPLOAD, [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO),
            new Transition(self::TRANSITION_FAIL, [OrderInterface::SHIPMONDO_STATE_PENDING, OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO], OrderInterface::SHIPMONDO_STATE_FAILED),
        ];
    }
}
