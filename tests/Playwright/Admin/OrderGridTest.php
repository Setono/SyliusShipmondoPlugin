<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Playwright\Admin;

use Playwright\Locator\LocatorInterface;
use function Playwright\Testing\expect;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Tests\Setono\SyliusShipmondoPlugin\Playwright\BrowserTestCase;

/**
 * The plugin adds a "Shipmondo state" column to the admin order grid, showing where the order is in the plugin's
 * upload workflow (pending, uploading_to_shipmondo, uploaded_to_shipmondo, failed)
 */
final class OrderGridTest extends BrowserTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->logInAsAdministrator();
    }

    /**
     * @test
     */
    public function a_new_order_is_pending_in_shipmondo(): void
    {
        $order = $this->createOrder();

        expect($this->shipmondoStateLabel($order))->toContainText('Pending');
    }

    /**
     * @test
     */
    public function a_failed_upload_is_shown_as_a_red_label(): void
    {
        $order = $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED);

        $label = $this->shipmondoStateLabel($order);
        expect($label)->toContainText('Failed');
        expect($label)->toHaveClass('ui red label');
    }

    /**
     * @test
     */
    public function orders_being_uploaded_and_uploaded_orders_show_their_translated_state(): void
    {
        $uploading = $this->createOrder(OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO);
        $uploaded = $this->createOrder(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001);

        expect($this->shipmondoStateLabel($uploading))->toContainText('Uploading to Shipmondo');
        expect($this->shipmondoStateLabel($uploaded))->toContainText('Uploaded to Shipmondo');
    }

    /**
     * @test
     */
    public function orders_being_uploaded_and_uploaded_orders_are_shown_with_coloured_labels_like_pending_and_failed_orders(): void
    {
        // the coloured label templates are still named after the old states (dispatching/dispatched), so these
        // states fall back to the plain grey label
        self::skipUntilFixed(25);

        $uploading = $this->createOrder(OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO);
        $uploaded = $this->createOrder(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001);

        expect($this->shipmondoStateLabel($uploading))->toHaveClass('ui olive label');
        expect($this->shipmondoStateLabel($uploaded))->toHaveClass('ui green label');
    }

    /**
     * The label in the order's "Shipmondo state" cell, found by filtering the grid on the order number
     */
    private function shipmondoStateLabel(OrderInterface $order): LocatorInterface
    {
        $number = (string) $order->getNumber();
        // the criteria the grid's filter form submits (Sylius's order repository requires the product and variant keys)
        $page = $this->visit(sprintf('/admin/orders/?criteria[product]=&criteria[variant]=&criteria[number][type]=equal&criteria[number][value]=%s', $number));

        $headers = $page->locator('table thead th')->evaluateAll('(headers) => headers.map((header) => header.textContent.trim())');
        self::assertIsArray($headers);
        $column = array_search('Shipmondo state', $headers, true);
        self::assertIsInt($column, 'The order grid has a "Shipmondo state" column');

        $row = $page->locator('table tbody tr')->filter(['hasText' => '#' . $number]);
        expect($row)->toHaveCount(1);

        return $row->locator('td')->nth($column)->locator('.ui.label');
    }
}
