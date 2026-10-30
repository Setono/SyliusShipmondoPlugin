import { randomUUID } from 'node:crypto';
import { Page } from '@playwright/test';
import { expect, test } from '../../support/admin-test';
import { flash } from '../../support/admin';
import { pickupPointShippingMethod, regularShippingMethod } from '../../support/config';

/*
 * The plugin adds "Pickup point delivery" and a carrier select to the shipping method form (the test app
 * renders them as the README describes) and injects carrier.js, which only shows the carrier select while
 * pickup point delivery is checked. The shipping method grid gets a "Pickup point delivery" column.
 */

const CARRIER_REQUIRED = 'When the pickup point delivery is set, you have to select a carrier.';

const carrierSelect = (page: Page) => page.locator('#sylius_shipping_method_carrierCode');
const pickupPointDeliveryCheckbox = (page: Page) => page.locator('#sylius_shipping_method_pickupPointDelivery');
const pickupPointDeliveryToggle = (page: Page) => page.locator('label[for="sylius_shipping_method_pickupPointDelivery"]');

/** Fills everything a new shipping method needs, created disabled so it never shows up in the shop specs */
async function fillNewShippingMethod(page: Page, code: string): Promise<void> {
    await page.locator('#sylius_shipping_method_code').fill(code);
    await page.locator('#sylius_shipping_method_zone').selectOption({ label: 'Rest of the World' });
    await page.locator('label[for="sylius_shipping_method_enabled"]').click();
    await expect(page.locator('#sylius_shipping_method_enabled')).not.toBeChecked();
    await page.getByText('Fashion Web Store', { exact: true }).first().click();
    await page.locator('#sylius_shipping_method_configuration_FASHION_WEB_amount').fill('4.95');
    await page.locator('#sylius_shipping_method_translations_en_US_name').fill(`E2E pickup point ${code}`);
}

test('the carrier select is only shown while pickup point delivery is checked', async ({ page }) => {
    await page.goto('/admin/shipping-methods/new');

    await expect(pickupPointDeliveryCheckbox(page)).not.toBeChecked();
    await expect(carrierSelect(page)).toBeHidden();

    await pickupPointDeliveryToggle(page).click();
    await expect(pickupPointDeliveryCheckbox(page)).toBeChecked();
    await expect(carrierSelect(page)).toBeVisible();
    await expect(carrierSelect(page).locator('option').first()).toHaveText('Select carrier...');
    await expect(carrierSelect(page).locator('option[value="gls"]')).toHaveText('GLS');
    await expect(carrierSelect(page).locator('option[value="post_nord"]')).toHaveText('PostNord');

    await pickupPointDeliveryToggle(page).click();
    await expect(pickupPointDeliveryCheckbox(page)).not.toBeChecked();
    await expect(carrierSelect(page)).toBeHidden();
});

test('a pickup point shipping method cannot be saved without a carrier', async ({ page }) => {
    await page.goto('/admin/shipping-methods/new');
    await fillNewShippingMethod(page, `e2e_${randomUUID().slice(0, 8)}`);
    await pickupPointDeliveryToggle(page).click();

    await page.getByRole('button', { name: 'Create' }).click();

    await expect(page.getByText(CARRIER_REQUIRED)).toBeVisible();
    // the form is shown again with pickup point delivery checked, so the carrier select is shown right away
    await expect(pickupPointDeliveryCheckbox(page)).toBeChecked();
    await expect(carrierSelect(page)).toBeVisible();
});

test('a pickup point shipping method is saved with its carrier and listed with it in the grid', async ({ page }) => {
    const code = `e2e_${randomUUID().slice(0, 8)}`;
    await page.goto('/admin/shipping-methods/new');
    await fillNewShippingMethod(page, code);
    await pickupPointDeliveryToggle(page).click();
    await carrierSelect(page).selectOption({ label: 'GLS' });

    await page.getByRole('button', { name: 'Create' }).click();

    await expect(flash(page, 'success')).toContainText('Shipping method has been successfully created.');
    await expect(pickupPointDeliveryCheckbox(page)).toBeChecked();
    await expect(carrierSelect(page)).toBeVisible();
    await expect(carrierSelect(page)).toHaveValue('gls');

    await page.goto('/admin/shipping-methods/');
    await expect(page.getByRole('row').filter({ hasText: code }).getByText('Yes (gls)')).toBeVisible();
});

test('the edit form shows the carrier of a pickup point shipping method right away', async ({ page }) => {
    await page.goto('/admin/shipping-methods/');
    await page.getByRole('row').filter({ hasText: pickupPointShippingMethod.code }).getByRole('link', { name: 'Edit' }).click();

    await expect(pickupPointDeliveryCheckbox(page)).toBeChecked();
    await expect(carrierSelect(page)).toBeVisible();
    await expect(carrierSelect(page)).toHaveValue(pickupPointShippingMethod.carrierCode);

    await pickupPointDeliveryToggle(page).click();
    await expect(carrierSelect(page)).toBeHidden();
});

test('the shipping method grid shows whether a method delivers to pickup points', async ({ page }) => {
    await page.goto('/admin/shipping-methods/');

    await expect(page.getByRole('columnheader', { name: 'Pickup point delivery' })).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: pickupPointShippingMethod.code }).getByText(`Yes (${pickupPointShippingMethod.carrierCode})`)).toBeVisible();
    await expect(page.getByRole('row').filter({ hasText: regularShippingMethod.code }).getByText('No', { exact: true })).toBeVisible();
});
