import { Page } from '@playwright/test';
import { expect, test } from '../../support/admin-test';
import { clickAndConfirm, flash } from '../../support/admin';
import { claimOrder, findOrder } from '../../support/db';
import { shipmondoSalesOrderId, StubLog } from '../../support/shipmondo-stub';

/*
 * When an admin cancels an order that was uploaded to Shipmondo, the plugin deletes the Shipmondo sales
 * order and flashes the outcome. The order is uploaded by a background command, so the specs arrange an
 * uploaded order by giving a fixture order a Shipmondo id; the stub answers the DELETE by that id.
 */

const DELETED = 'The order was deleted in Shipmondo because it was cancelled.';
const NOT_DELETED = 'The order was cancelled, but it could not be deleted in Shipmondo. Delete it in Shipmondo manually.';

async function cancelOrder(page: Page, orderId: number): Promise<void> {
    await page.goto(`/admin/orders/${orderId}`);
    await clickAndConfirm(page, page.getByRole('button', { name: 'Cancel' }));
    await expect(flash(page, 'success')).toBeVisible();
}

test('cancelling an uploaded order deletes it in Shipmondo', async ({ page }) => {
    const shipmondoId = shipmondoSalesOrderId('deleted');
    const order = await claimOrder({ id: shipmondoId, state: 'uploaded_to_shipmondo' });
    const log = StubLog.mark();

    await cancelOrder(page, order.id);

    await expect(flash(page, 'info')).toContainText(DELETED);
    await expect(flash(page, 'error')).toHaveCount(0);
    expect(log.requests('DELETE', new RegExp(`/sales_orders/${shipmondoId}$`))).toHaveLength(1);
    expect(await findOrder(order.id)).toEqual({ state: 'cancelled', shipmondoId: null });
});

test('an order that is already gone from Shipmondo counts as deleted', async ({ page }) => {
    const shipmondoId = shipmondoSalesOrderId('not_found');
    const order = await claimOrder({ id: shipmondoId, state: 'uploaded_to_shipmondo' });

    await cancelOrder(page, order.id);

    await expect(flash(page, 'info')).toContainText(DELETED);
    expect(await findOrder(order.id)).toEqual({ state: 'cancelled', shipmondoId: null });
});

test('the order is still cancelled when Shipmondo fails to delete it, and the admin is told to do it manually', async ({ page }) => {
    const shipmondoId = shipmondoSalesOrderId('server_error');
    const order = await claimOrder({ id: shipmondoId, state: 'uploaded_to_shipmondo' });

    await cancelOrder(page, order.id);

    await expect(flash(page, 'error')).toContainText(NOT_DELETED);
    await expect(flash(page, 'info')).toHaveCount(0);
    // the order keeps its Shipmondo id, so it can still be deleted later
    expect(await findOrder(order.id)).toEqual({ state: 'cancelled', shipmondoId });
});

test('cancelling an order that was never uploaded does not involve Shipmondo', async ({ page }) => {
    const order = await claimOrder();
    const log = StubLog.mark();

    await cancelOrder(page, order.id);

    await expect(flash(page, 'info')).toHaveCount(0);
    await expect(flash(page, 'error')).toHaveCount(0);
    expect(log.requests('DELETE', /\/sales_orders\//)).toHaveLength(0);
    expect((await findOrder(order.id)).state).toBe('cancelled');
});
