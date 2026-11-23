<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Playwright\Admin;

use function Playwright\Testing\expect;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Tests\Setono\SyliusShipmondoPlugin\Playwright\BrowserTestCase;

/**
 * An admin retries the upload of a failed order from its page, or finds the failed orders with the order grid's
 * "Shipmondo state" filter and retries them all with the grid's bulk action. A retried order is pending again, for the
 * next upload run: nothing is asked of Shipmondo
 */
final class RetryUploadTest extends BrowserTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->logInAsAdministrator();
    }

    /**
     * @test
     */
    public function an_admin_retries_the_upload_of_a_failed_order_from_its_page(): void
    {
        $order = $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED);

        $page = $this->visit(sprintf('/admin/orders/%d', self::id($order)));
        $section = $page->locator('#setono-sylius-shipmondo-order');
        expect($section->locator('.ui.label'))->toHaveText('Failed');

        $this->clickAndFollowRedirects($section->getByRole('button', ['name' => 'Retry upload']));

        expect($this->flash('success'))->toContainText('The order is pending again, so the next upload run will upload it to Shipmondo.');
        $section = $this->getPage()->locator('#setono-sylius-shipmondo-order');
        expect($section->locator('.ui.label'))->toHaveText('Pending');
        expect($section->getByRole('button', ['name' => 'Retry upload']))->toHaveCount(0);
        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $this->shipmondoState($order));
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * After a shop-wide problem, e.g. a mapped payment gateway deleted in Shipmondo, has been solved
     *
     * @test
     */
    public function an_admin_filters_the_order_grid_on_failed_orders_and_retries_their_upload_in_bulk(): void
    {
        $failed = [$this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED), $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED)];
        $uploaded = $this->createOrder(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001);

        $page = $this->visit('/admin/orders/');
        // the grid's select filters are plain selects, which the admin's scripts leave as they are
        $page->getByLabel('Shipmondo state')->selectOption(['label' => 'Failed']);
        $this->clickAndFollowRedirects($page->getByRole('button', ['name' => 'Filter']));

        $page = $this->getPage();
        $rows = $page->locator('table tbody tr');
        expect($rows)->toHaveCount(2);
        foreach ($failed as $order) {
            expect($rows->filter(['hasText' => '#' . (string) $order->getNumber()]))->toHaveCount(1);
        }

        // selects every listed order, which enables the grid's bulk actions
        $page->locator('table thead input[type="checkbox"]')->check();
        $this->clickAndConfirm($page->getByRole('button', ['name' => 'Retry upload to Shipmondo']));

        expect($this->flash('success'))->toContainText('2 orders are pending again, so the next upload run will upload them to Shipmondo.');
        expect($this->flash('info'))->toHaveCount(0);
        foreach ($failed as $order) {
            self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $this->shipmondoState($order));
        }
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $this->shipmondoState($uploaded));
        self::assertSame([], $this->shipmondo->requests());
    }

    private function shipmondoState(OrderInterface $order): string
    {
        $state = $this->connection()->fetchOne('SELECT shipmondoState FROM sylius_order WHERE id = ?', [self::id($order)]);
        self::assertIsString($state);

        return $state;
    }
}
