import { Locator, Page } from '@playwright/test';
import { expect, test } from '../../support/admin-test';
import { claimOrder, ClaimedOrder, releaseOrder } from '../../support/db';

/*
 * The plugin adds a "Shipmondo state" column to the admin order grid, showing where the order is in the
 * plugin's upload workflow (pending, uploading_to_shipmondo, uploaded_to_shipmondo, failed).
 */

const claimed: ClaimedOrder[] = [];

/** Claims a fixture order in the given Shipmondo state; it is released again after the test */
async function orderInState(state: string): Promise<ClaimedOrder> {
    const order = await claimOrder({ state });
    claimed.push(order);

    return order;
}

test.afterEach(async () => {
    await Promise.all(claimed.splice(0).map(releaseOrder));
});

/** The Shipmondo state cell of the order, found by filtering the grid on the order number */
async function shipmondoStateCell(page: Page, orderNumber: string): Promise<Locator> {
    // the same criteria the grid's filter form submits (Sylius's order repository requires the product and variant keys)
    await page.goto(`/admin/orders/?criteria[product]=&criteria[variant]=&criteria[number][type]=equal&criteria[number][value]=${orderNumber}`);

    const headers = await page.locator('table thead th').allTextContents();
    const column = headers.findIndex((header) => header.trim() === 'Shipmondo state');
    expect(column, 'the order grid has a "Shipmondo state" column').toBeGreaterThan(-1);

    const row = page.locator('table tbody tr').filter({ hasText: `#${orderNumber}` });
    await expect(row).toHaveCount(1);

    return row.locator('td').nth(column);
}

test('a new order is pending in Shipmondo', async ({ page }) => {
    const order = await orderInState('pending');

    const cell = await shipmondoStateCell(page, order.number);

    await expect(cell.locator('.ui.label')).toHaveText('Pending');
});

test('a failed upload is shown as a red label', async ({ page }) => {
    const order = await orderInState('failed');

    const cell = await shipmondoStateCell(page, order.number);

    await expect(cell.locator('.ui.label')).toHaveText('Failed');
    await expect(cell.locator('.ui.label')).toHaveClass(/\bred\b/);
});

test('orders being uploaded and uploaded orders show their translated state', async ({ page }) => {
    const uploading = await orderInState('uploading_to_shipmondo');
    const uploaded = await orderInState('uploaded_to_shipmondo');

    await expect((await shipmondoStateCell(page, uploading.number)).locator('.ui.label')).toHaveText('Uploading to Shipmondo');
    await expect((await shipmondoStateCell(page, uploaded.number)).locator('.ui.label')).toHaveText('Uploaded to Shipmondo');
});

// Bug: the coloured label templates are still named after the old states (dispatching/dispatched), so these
// states fall back to the plain grey label
// https://github.com/Setono/SyliusShipmondoPlugin/issues/25
test.fixme('orders being uploaded and uploaded orders are shown with coloured labels like pending and failed orders', async ({ page }) => {
    const uploading = await orderInState('uploading_to_shipmondo');
    const uploaded = await orderInState('uploaded_to_shipmondo');

    await expect((await shipmondoStateCell(page, uploading.number)).locator('.ui.label')).toHaveClass(/\bolive\b/);
    await expect((await shipmondoStateCell(page, uploaded.number)).locator('.ui.label')).toHaveClass(/\bgreen\b/);
});
