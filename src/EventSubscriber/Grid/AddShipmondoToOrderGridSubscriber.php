<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\EventSubscriber\Grid;

use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Component\Grid\Definition\Action;
use Sylius\Component\Grid\Definition\ActionGroup;
use Sylius\Component\Grid\Definition\Field;
use Sylius\Component\Grid\Definition\Filter;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\Event\GridDefinitionConverterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the order's Shipmondo state to the admin order grid, as a column and as a filter, and a bulk action that retries
 * the upload of the selected failed orders
 */
final class AddShipmondoToOrderGridSubscriber implements EventSubscriberInterface
{
    /**
     * The bulk action's name and type. The extension maps the type to the bulk action's template
     */
    final public const RETRY_UPLOAD_BULK_ACTION = 'setono_sylius_shipmondo_retry_upload';

    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.grid.admin_order' => 'add',
        ];
    }

    public function add(GridDefinitionConverterEvent $event): void
    {
        $grid = $event->getGrid();

        $field = Field::fromNameAndType('shipmondoState', 'twig');
        $field->setOptions([
            'template' => '@SetonoSyliusShipmondoPlugin/admin/order/grid/field/shipmondo_state.html.twig',
            'vars' => [
                'labels' => '@SetonoSyliusShipmondoPlugin/admin/order/grid/field/shipmondo_state',
            ],
        ]);
        $field->setLabel('setono_sylius_shipmondo.ui.shipmondo_state');
        $grid->addField($field);

        $filter = Filter::fromNameAndType('shipmondo_state', 'select');
        $filter->setLabel('setono_sylius_shipmondo.ui.shipmondo_state');
        $filter->setOptions(['field' => 'shipmondoState']);
        $choices = [];
        foreach (OrderWorkflow::getStates() as $state) {
            $choices['setono_sylius_shipmondo.ui.' . $state] = $state;
        }
        $filter->setFormOptions(['choices' => $choices]);
        $grid->addFilter($filter);

        $retryUpload = Action::fromNameAndType(self::RETRY_UPLOAD_BULK_ACTION, self::RETRY_UPLOAD_BULK_ACTION);
        $retryUpload->setLabel('setono_sylius_shipmondo.ui.retry_upload_to_shipmondo');
        $retryUpload->setIcon('redo');
        self::bulkActions($grid)->addAction($retryUpload);
    }

    private static function bulkActions(Grid $grid): ActionGroup
    {
        if (!$grid->hasActionGroup('bulk')) {
            $grid->addActionGroup(ActionGroup::named('bulk'));
        }

        return $grid->getActionGroup('bulk');
    }
}
