import { Locator, Page } from '@playwright/test';
import { expect, test } from '../../support/admin-test';
import { flash, pickDropdownOption, submitForm } from '../../support/admin';
import { baseURL, regularShippingMethod } from '../../support/config';
import { execute, forgetRegisteredWebhooks, resetShipmondoMappings } from '../../support/db';
import { existingWebhooks, paymentGateways, shipmentTemplates, StubLog, useStubScenario } from '../../support/shipmondo-stub';

/*
 * The admin "Shipmondo" page (Configuration > Shipmondo) maps Sylius payment methods to Shipmondo payment
 * gateways and Sylius shipping methods to Shipmondo shipment templates, and registers the webhooks.
 * Server side the page talks to the stubbed Shipmondo API of the e2e environment.
 */

// The tests share the mappings, so they run one after the other
test.describe.configure({ mode: 'serial' });

const API_ERROR = 'Could not fetch data from the Shipmondo API. Please check your Shipmondo credentials.';
const NO_PAYMENT_GATEWAYS = 'There are no configured payment gateways inside Shipmondo.';
const WEBHOOKS_NOT_REGISTERED = 'Either you have not registered webhooks yet or you need to re-register them';

const quickPay = paymentGateways.find((gateway) => gateway.name === 'QuickPay')!;
const [homeDeliveryTemplate, pickupParcelTemplate] = shipmentTemplates;

const paymentMethodsForm = (page: Page): Locator => page.locator('form').filter({ has: page.locator('select[name^="payment_methods"]') });
const shippingMethodsForm = (page: Page): Locator => page.locator('form').filter({ has: page.locator('select[name^="shipping_methods"]') });
const mappingRow = (form: Locator, name: string): Locator => form.getByRole('row').filter({ has: form.page().getByRole('cell', { name, exact: true }) });
const selectedValues = (select: Locator): Promise<string[]> => select.evaluate((element: HTMLSelectElement) => [...element.selectedOptions].map((option) => option.value));

test.beforeEach(async () => {
    await resetShipmondoMappings();
});

test('the page is reachable from the Configuration menu', async ({ page }) => {
    await page.goto('/admin/');

    const configurationMenu = page.locator('.sylius-admin-menu > .item').filter({ has: page.locator('.header', { hasText: 'Configuration' }) });
    await configurationMenu.getByRole('link', { name: 'Shipmondo' }).click();

    await expect(page).toHaveURL(/\/admin\/shipmondo$/);
    await expect(page.getByRole('heading', { name: 'Shipmondo' })).toBeVisible();
    await expect(page.getByText('Manage your Shipmondo settings')).toBeVisible();
});

test('a payment method is mapped to a Shipmondo payment gateway', async ({ page }) => {
    await page.goto('/admin/shipmondo');
    const row = mappingRow(paymentMethodsForm(page), 'Cash on delivery');
    await expect(row.locator('.ui.dropdown .menu .item')).toHaveText(paymentGateways.map((gateway) => gateway.name));

    await pickDropdownOption(row.locator('.ui.dropdown'), quickPay.name);
    await submitForm(page, paymentMethodsForm(page).getByRole('button', { name: 'Save changes' }));

    await expect(mappingRow(paymentMethodsForm(page), 'Cash on delivery').locator('select')).toHaveValue(String(quickPay.id));
    await expect(mappingRow(paymentMethodsForm(page), 'Cash on delivery').locator('.ui.dropdown > .text')).toHaveText(quickPay.name);
    await expect(mappingRow(paymentMethodsForm(page), 'Bank transfer').locator('select')).toHaveValue('');

    // the mapping is stored, not just kept in the re-rendered form
    await page.goto('/admin/shipmondo');
    await expect(mappingRow(paymentMethodsForm(page), 'Cash on delivery').locator('select')).toHaveValue(String(quickPay.id));
});

test('shipment templates are mapped to a shipping method', async ({ page }) => {
    await page.goto('/admin/shipmondo');
    const dropdown = mappingRow(shippingMethodsForm(page), regularShippingMethod.name).locator('.ui.dropdown');

    await pickDropdownOption(dropdown, homeDeliveryTemplate.name);
    await page.keyboard.press('Escape');
    await pickDropdownOption(dropdown, pickupParcelTemplate.name);
    await page.keyboard.press('Escape');
    await submitForm(page, shippingMethodsForm(page).getByRole('button', { name: 'Save changes' }));

    await page.goto('/admin/shipmondo');
    const row = mappingRow(shippingMethodsForm(page), regularShippingMethod.name);
    expect((await selectedValues(row.locator('select'))).sort()).toEqual([String(homeDeliveryTemplate.id), String(pickupParcelTemplate.id)].sort());
    await expect(row.locator('.ui.dropdown a.ui.label')).toHaveText([homeDeliveryTemplate.name, pickupParcelTemplate.name], { useInnerText: true });
    expect(await selectedValues(mappingRow(shippingMethodsForm(page), 'FedEx').locator('select'))).toEqual([]);
});

test('the webhooks are registered with Shipmondo', async ({ page }) => {
    await forgetRegisteredWebhooks();
    await page.goto('/admin/shipmondo');
    await expect(page.getByText(WEBHOOKS_NOT_REGISTERED)).toBeVisible();

    const log = StubLog.mark();
    await page.getByRole('link', { name: 'Register webhooks' }).click();

    await expect(page).toHaveURL(/\/admin\/shipmondo$/);
    await expect(flash(page, 'success')).toContainText('Webhooks registered successfully');
    await expect(page.getByText(/^Webhooks were registered on .+\.$/)).toBeVisible();
    await expect(page.getByText(WEBHOOKS_NOT_REGISTERED)).toBeHidden();

    // The plugin replaces its own previously registered webhooks, leaves other webhooks alone, and points
    // the new ones at the shop's webhook endpoint
    const [ownWebhook, otherWebhook] = [existingWebhooks[1], existingWebhooks[0]];
    expect(log.requests('DELETE', new RegExp(`/webhooks/${ownWebhook.id}$`))).toHaveLength(1);
    expect(log.requests('DELETE', new RegExp(`/webhooks/${otherWebhook.id}$`))).toHaveLength(0);

    const created = log.requests('POST', /\/webhooks$/).map((entry) => entry.body as { name: string; endpoint: string });
    expect(created.length).toBeGreaterThan(0);
    for (const webhook of created) {
        expect(webhook.name).toMatch(/^Setono Sylius Shipmondo Plugin - \[.+\]$/);
        expect(webhook.endpoint).toBe(`${baseURL}/webhook/shipmondo`);
    }
});

test('the page explains the problem when Shipmondo rejects the credentials', async ({ page, context }) => {
    await useStubScenario(context, 'unauthorized');

    await page.goto('/admin/shipmondo');

    await expect(flash(page, 'error')).toContainText(API_ERROR);
    await expect(page.getByText(NO_PAYMENT_GATEWAYS)).toBeVisible();
    await expect(page.locator('select[name^="payment_methods"]')).toHaveCount(0);
    await expect(page.locator('select[name^="shipping_methods"]')).toHaveCount(0);
    // the webhooks can still be registered once the credentials are fixed
    await expect(page.getByRole('link', { name: 'Register webhooks' })).toBeVisible();
});

test('the page tells the admin to create a payment gateway when Shipmondo has none', async ({ page, context }) => {
    await useStubScenario(context, 'empty');

    await page.goto('/admin/shipmondo');

    await expect(flash(page, 'error')).toHaveCount(0);
    await expect(page.getByText(NO_PAYMENT_GATEWAYS)).toBeVisible();
    await expect(page.getByRole('link', { name: 'Shipmondo', exact: true }).last()).toHaveAttribute('href', /app\.shipmondo\.com/);
    await expect(page.locator('select[name^="payment_methods"]')).toHaveCount(0);
});

// Bug: the empty state for shipment templates renders an untranslated message key
// https://github.com/Setono/SyliusShipmondoPlugin/issues/19
test.fixme('the page tells the admin to create a shipment template when Shipmondo has none', async ({ page, context }) => {
    await useStubScenario(context, 'empty');

    await page.goto('/admin/shipmondo');

    const segment = page.locator('.ui.segment').filter({ has: page.getByRole('heading', { name: 'Map shipping methods' }) });
    await expect(segment).not.toContainText('setono_sylius_shipmondo.ui.');
    await expect(segment).toContainText(/shipment template/i);
});

// Bug: an empty choice is skipped when saving, so a mapping can never be removed (and the dropdown offers no
// empty choice, so the spec empties the native select)
// https://github.com/Setono/SyliusShipmondoPlugin/issues/18
test.fixme('a payment method mapping can be removed', async ({ page }) => {
    await execute("UPDATE sylius_payment_method SET shipmondoId = ? WHERE code = 'cash_on_delivery'", [quickPay.id]);
    await page.goto('/admin/shipmondo');
    const select = mappingRow(paymentMethodsForm(page), 'Cash on delivery').locator('select');
    await expect(select).toHaveValue(String(quickPay.id));

    await select.selectOption('', { force: true });
    await submitForm(page, paymentMethodsForm(page).getByRole('button', { name: 'Save changes' }));

    await page.goto('/admin/shipmondo');
    await expect(mappingRow(paymentMethodsForm(page), 'Cash on delivery').locator('select')).toHaveValue('');
});

// Bug: a multi-select without any selected option is not submitted, so the last template can never be removed
// https://github.com/Setono/SyliusShipmondoPlugin/issues/18
test.fixme('all shipment templates can be removed from a shipping method', async ({ page }) => {
    await execute('UPDATE sylius_shipping_method SET allowedShipmentTemplates = ? WHERE code = ?', [JSON.stringify([homeDeliveryTemplate.id]), regularShippingMethod.code]);
    await page.goto('/admin/shipmondo');
    const row = mappingRow(shippingMethodsForm(page), regularShippingMethod.name);
    await expect(row.locator('.ui.dropdown a.ui.label')).toHaveText([homeDeliveryTemplate.name], { useInnerText: true });

    await row.locator('.ui.dropdown a.ui.label .delete.icon').click();
    await submitForm(page, shippingMethodsForm(page).getByRole('button', { name: 'Save changes' }));

    await page.goto('/admin/shipmondo');
    expect(await selectedValues(mappingRow(shippingMethodsForm(page), regularShippingMethod.name).locator('select'))).toEqual([]);
});

// Bug: registering the webhooks while the Shipmondo API fails ends in an HTTP 500 error page
// https://github.com/Setono/SyliusShipmondoPlugin/issues/24
test.fixme('registering the webhooks explains the problem when Shipmondo rejects the credentials', async ({ page, context }) => {
    await useStubScenario(context, 'unauthorized');
    await page.goto('/admin/shipmondo');

    const [response] = await Promise.all([
        page.waitForResponse((candidate) => candidate.url().endsWith('/admin/shipmondo/register-webhooks')),
        page.getByRole('link', { name: 'Register webhooks' }).click(),
    ]);

    expect(response.status()).toBeLessThan(500);
    await expect(page).toHaveURL(/\/admin\/shipmondo$/);
    await expect(flash(page, 'error')).toBeVisible();
});
