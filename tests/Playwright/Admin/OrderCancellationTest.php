<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Playwright\Admin;

use function Playwright\Testing\expect;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Tests\Setono\SyliusShipmondoPlugin\Playwright\BrowserTestCase;

/**
 * When an admin cancels an order that was uploaded to Shipmondo, the plugin deletes the Shipmondo sales order and
 * flashes the outcome on the order page
 */
final class OrderCancellationTest extends BrowserTestCase
{
    private const DELETED = 'The order was deleted in Shipmondo because it was cancelled.';

    private const NOT_DELETED = 'The order was cancelled, but it could not be deleted in Shipmondo. Delete it in Shipmondo manually.';

    private const SHIPMONDO_ID = 1001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logInAsAdministrator();
    }

    /**
     * @test
     */
    public function cancelling_an_uploaded_order_deletes_it_in_shipmondo(): void
    {
        $order = $this->createUploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, 204, null);

        $this->cancel($order);

        expect($this->flash('info'))->toContainText(self::DELETED);
        expect($this->flash('error'))->toHaveCount(0);
        self::assertCount(1, $this->shipmondo->requests('DELETE', 'sales_orders/' . self::SHIPMONDO_ID));
        self::assertSame(['state' => 'cancelled', 'shipmondo_id' => null], $this->orderRow(self::id($order)));
    }

    /**
     * @test
     */
    public function an_order_that_is_already_gone_from_shipmondo_counts_as_deleted(): void
    {
        $order = $this->createUploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, 404, ['error' => 'Not found']);

        $this->cancel($order);

        expect($this->flash('info'))->toContainText(self::DELETED);
        self::assertSame(['state' => 'cancelled', 'shipmondo_id' => null], $this->orderRow(self::id($order)));
    }

    /**
     * @test
     */
    public function the_order_is_still_cancelled_when_shipmondo_fails_to_delete_it_and_the_admin_is_told_to_do_it_manually(): void
    {
        $order = $this->createUploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, 500, ['error' => 'Internal server error']);

        $this->cancel($order);

        expect($this->flash('error'))->toContainText(self::NOT_DELETED);
        expect($this->flash('info'))->toHaveCount(0);
        // the order keeps its Shipmondo id, so it can still be deleted later
        self::assertSame(['state' => 'cancelled', 'shipmondo_id' => self::SHIPMONDO_ID], $this->orderRow(self::id($order)));
    }

    /**
     * @test
     */
    public function cancelling_an_order_that_was_never_uploaded_does_not_involve_shipmondo(): void
    {
        // any request to Shipmondo would be one this test did not stub, which fails it
        $order = $this->createOrder();

        $this->cancel($order);

        expect($this->flash('info'))->toHaveCount(0);
        expect($this->flash('error'))->toHaveCount(0);
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame('cancelled', $this->orderRow(self::id($order))['state']);
    }

    private function createUploadedOrder(): OrderInterface
    {
        return $this->createOrder(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, self::SHIPMONDO_ID);
    }

    private function cancel(OrderInterface $order): void
    {
        $page = $this->visit(sprintf('/admin/orders/%d', self::id($order)));
        $this->clickAndConfirm($page->getByRole('button', ['name' => 'Cancel']));

        expect($this->flash('success'))->toBeVisible();
    }
}
