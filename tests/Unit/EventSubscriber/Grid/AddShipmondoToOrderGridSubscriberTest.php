<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\EventSubscriber\Grid;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\EventSubscriber\Grid\AddShipmondoToOrderGridSubscriber;
use Sylius\Component\Grid\Definition\Action;
use Sylius\Component\Grid\Definition\ActionGroup;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\Event\GridDefinitionConverterEvent;

final class AddShipmondoToOrderGridSubscriberTest extends TestCase
{
    /**
     * @test
     */
    public function it_subscribes_to_the_admin_order_grid(): void
    {
        self::assertSame(
            ['sylius.grid.admin_order' => 'add'],
            AddShipmondoToOrderGridSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * @test
     */
    public function it_adds_a_shipmondo_state_column(): void
    {
        $grid = self::orderGrid();

        self::add($grid);

        $field = $grid->getField('shipmondoState');
        self::assertSame('twig', $field->getType());
        self::assertSame('shipmondoState', $field->getPath());
        self::assertSame('setono_sylius_shipmondo.ui.shipmondo_state', $field->getLabel());
        self::assertSame([
            'template' => '@SetonoSyliusShipmondoPlugin/admin/order/grid/field/shipmondo_state.html.twig',
            'vars' => [
                'labels' => '@SetonoSyliusShipmondoPlugin/admin/order/grid/field/shipmondo_state',
            ],
        ], $field->getOptions());
    }

    /**
     * After a shop-wide problem, e.g. a mapped payment gateway deleted in Shipmondo, an admin finds the failed orders
     * to retry them
     *
     * @test
     */
    public function it_adds_a_filter_on_every_shipmondo_state(): void
    {
        $grid = self::orderGrid();

        self::add($grid);

        $filter = $grid->getFilter('shipmondo_state');
        self::assertSame('select', $filter->getType());
        self::assertSame('setono_sylius_shipmondo.ui.shipmondo_state', $filter->getLabel());
        self::assertSame(['field' => 'shipmondoState'], $filter->getOptions());
        self::assertSame(['choices' => [
            'setono_sylius_shipmondo.ui.pending' => 'pending',
            'setono_sylius_shipmondo.ui.uploading_to_shipmondo' => 'uploading_to_shipmondo',
            'setono_sylius_shipmondo.ui.uploaded_to_shipmondo' => 'uploaded_to_shipmondo',
            'setono_sylius_shipmondo.ui.unlinked' => 'unlinked',
            'setono_sylius_shipmondo.ui.failed' => 'failed',
            'setono_sylius_shipmondo.ui.cancelled' => 'cancelled',
        ]], $filter->getFormOptions());
    }

    /**
     * Sylius's order grid has no bulk actions, so the plugin adds the group the action goes in
     *
     * @test
     */
    public function it_adds_a_bulk_action_that_retries_the_upload_of_the_selected_orders(): void
    {
        $grid = self::orderGrid();

        self::add($grid);

        self::assertSame(['setono_sylius_shipmondo_retry_upload'], array_keys($grid->getEnabledActions('bulk')));
        self::assertRetryUploadAction($grid->getActionGroup('bulk')->getAction('setono_sylius_shipmondo_retry_upload'));
    }

    /**
     * @test
     */
    public function it_adds_the_bulk_action_next_to_the_bulk_actions_a_store_added_to_the_order_grid(): void
    {
        $grid = self::orderGrid();
        $bulk = ActionGroup::named('bulk');
        $bulk->addAction(Action::fromNameAndType('export', 'export'));
        $grid->addActionGroup($bulk);

        self::add($grid);

        self::assertSame(['export', 'setono_sylius_shipmondo_retry_upload'], array_keys($grid->getActions('bulk')));
        self::assertRetryUploadAction($grid->getActionGroup('bulk')->getAction('setono_sylius_shipmondo_retry_upload'));
    }

    private static function assertRetryUploadAction(Action $action): void
    {
        self::assertSame('setono_sylius_shipmondo_retry_upload', $action->getType());
        self::assertSame('setono_sylius_shipmondo.ui.retry_upload_to_shipmondo', $action->getLabel());
        self::assertSame('redo', $action->getIcon());
    }

    private static function orderGrid(): Grid
    {
        return Grid::fromCodeAndDriverConfiguration('sylius_admin_order', 'doctrine/orm', []);
    }

    private static function add(Grid $grid): void
    {
        (new AddShipmondoToOrderGridSubscriber())->add(new GridDefinitionConverterEvent($grid));
    }
}
