import { expect, Page, test } from '@playwright/test';
import { pickupPointShippingMethod, regularShippingMethod } from '../../support/config';
import { pickupPoints } from '../../support/shipmondo-stub';
import {
    checkoutToShippingStep,
    interceptPickupPoints,
    PICKUP_POINTS_ENDPOINT,
    pickupPointList,
    pickupPointOption,
    pickupPointRadios,
    pickupPointsResponse,
    selectShippingMethod,
    shippingMethodRadio,
    uniqueEmail,
} from '../../support/shop';

/*
 * The pickup point JavaScript (src/Resources/public/js/pickup-point-manager.js) on the shop's shipping
 * step. These specs answer the plugin's pickup point endpoint in the browser (page.route), so they test the
 * JavaScript against a fixed response; pickup-point-checkout.spec.ts covers the real endpoint.
 */

const PICKUP_POINT_FIELD = 'sylius_checkout_select_shipping[shipments][0][shipmondoPickupPoint]';

/** Counts the events the pickup point manager dispatches on the page */
async function recordPickupPointEvents(page: Page): Promise<void> {
    await page.evaluate(() => {
        const events: Record<string, string[]> = { loading: [], inserted_html: [] };
        (window as unknown as { pickupPointEvents: typeof events }).pickupPointEvents = events;
        for (const name of Object.keys(events)) {
            document.addEventListener(`pickup_point_manager:${name}`, (event) => events[name].push((event as CustomEvent).detail.shippingMethod));
        }
    });
}

function pickupPointEvents(page: Page, name: 'loading' | 'inserted_html'): Promise<string[]> {
    return page.evaluate((eventName) => (window as unknown as { pickupPointEvents: Record<string, string[]> }).pickupPointEvents[eventName], name);
}

test('pickup points are only requested for pickup point shipping methods', async ({ page }) => {
    const requested = await interceptPickupPoints(page);

    await checkoutToShippingStep(page, uniqueEmail());
    await page.waitForLoadState('networkidle');

    // they are requested when the step loads, before the customer picks a shipping method
    expect(requested).toEqual([pickupPointShippingMethod.code]);
    await expect(shippingMethodRadio(page, regularShippingMethod.code)).toBeChecked();
    await expect(pickupPointList(page)).toHaveCount(0);
});

test('selecting a pickup point shipping method lists its pickup points to choose from', async ({ page }) => {
    await interceptPickupPoints(page);
    await checkoutToShippingStep(page, uniqueEmail());
    await recordPickupPointEvents(page);

    await selectShippingMethod(page, pickupPointShippingMethod.name);

    await expect(pickupPointList(page)).toBeVisible();
    // the list is inserted right below the selected shipping method
    expect(
        await shippingMethodRadio(page, pickupPointShippingMethod.code).evaluate((radio) => radio.closest('.item')?.nextElementSibling?.classList.contains('pickup-points-container')),
    ).toBe(true);

    for (const point of pickupPoints) {
        const option = pickupPointOption(page, point.name);
        await expect(option).toContainText(point.address);
        await expect(option).toContainText(`${point.zipcode} ${point.city}`);
        for (const hours of point.opening_hours) {
            await expect(option).toContainText(hours);
        }
    }

    // the radios are named so they are submitted with the shipment, and the first pickup point is preselected
    await expect(pickupPointRadios(page)).toHaveCount(pickupPoints.length);
    for (const radio of await pickupPointRadios(page).all()) {
        await expect(radio).toHaveAttribute('name', PICKUP_POINT_FIELD);
    }
    await expect(pickupPointRadios(page).first()).toBeChecked();

    await pickupPointOption(page, pickupPoints[1].name).locator('input[type="radio"]').check();
    await expect(pickupPointRadios(page).nth(1)).toBeChecked();
    await expect(pickupPointRadios(page).first()).not.toBeChecked();

    expect(await pickupPointEvents(page, 'inserted_html')).toEqual([pickupPointShippingMethod.code]);
});

test('the pickup points are removed for another shipping method and come back without a new request', async ({ page }) => {
    const requested = await interceptPickupPoints(page);
    await checkoutToShippingStep(page, uniqueEmail());

    await selectShippingMethod(page, pickupPointShippingMethod.name);
    await expect(pickupPointList(page)).toBeVisible();

    await selectShippingMethod(page, regularShippingMethod.name);
    await expect(pickupPointList(page)).toHaveCount(0);

    await selectShippingMethod(page, pickupPointShippingMethod.name);
    await expect(pickupPointList(page)).toBeVisible();
    await expect(pickupPointRadios(page)).toHaveCount(pickupPoints.length);

    expect(requested).toEqual([pickupPointShippingMethod.code]);
});

test('when the pickup points cannot be loaded none are shown and the checkout can continue', async ({ page }) => {
    const consoleErrors: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });
    await interceptPickupPoints(page, (route) => route.fulfill({ status: 500, json: { error: 'Internal Server Error' } }));
    const failedResponse = page.waitForResponse(PICKUP_POINTS_ENDPOINT);

    await checkoutToShippingStep(page, uniqueEmail());
    await failedResponse;
    await selectShippingMethod(page, pickupPointShippingMethod.name);

    await expect.poll(() => consoleErrors.some((error) => error.startsWith('Failed to load pickup point data'))).toBe(true);
    await expect(pickupPointList(page)).toHaveCount(0);

    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page).toHaveURL(/\/en_US\/checkout\/select-payment$/);
});

// Bug: when the customer selects a pickup point shipping method and then another one before the pickup points
// have loaded, the manager still inserts them once they arrive, below the pickup point method although the other
// method is selected. The preselected pickup point is then submitted and saved on the other method's shipment.
// https://github.com/Setono/SyliusShipmondoPlugin/issues/27
test.fixme('switching to another shipping method before the pickup points have loaded does not show them', async ({ page }) => {
    let release = (): void => undefined;
    const released = new Promise<void>((resolve) => {
        release = resolve;
    });
    await interceptPickupPoints(page, async (route) => {
        await released;
        await route.fulfill({ json: pickupPointsResponse() });
    });
    await checkoutToShippingStep(page, uniqueEmail());

    await selectShippingMethod(page, pickupPointShippingMethod.name);
    await selectShippingMethod(page, regularShippingMethod.name);
    const response = page.waitForResponse(PICKUP_POINTS_ENDPOINT);
    release();
    await response;
    // give the manager, which checks for the data every 100 ms, time to (wrongly) insert the list
    await page.waitForTimeout(1_000);

    await expect(shippingMethodRadio(page, regularShippingMethod.code)).toBeChecked();
    await expect(pickupPointList(page)).toHaveCount(0);
});

// Bug: when loading the pickup points fails, the manager keeps waiting for them forever: it dispatches a
// loading event and retries every 100 ms for as long as the page is open, and the customer gets no feedback
// https://github.com/Setono/SyliusShipmondoPlugin/issues/23
test.fixme('when the pickup points cannot be loaded the manager stops waiting for them', async ({ page }) => {
    await interceptPickupPoints(page, (route) => route.fulfill({ status: 500, json: { error: 'Internal Server Error' } }));
    const failedResponse = page.waitForResponse(PICKUP_POINTS_ENDPOINT);
    await checkoutToShippingStep(page, uniqueEmail());
    await failedResponse;
    await recordPickupPointEvents(page);

    await selectShippingMethod(page, pickupPointShippingMethod.name);
    await page.waitForTimeout(3_000);

    expect((await pickupPointEvents(page, 'loading')).length).toBeLessThan(5);
});

// Bug: when no shipping method has pickup point delivery the server renders `allowedShippingMethods: []`, and
// the manager treats an empty list as "every shipping method": it requests pickup points for all of them
// (each request fails with HTTP 400) and then waits forever for the preselected method. This spec sets the
// same empty list through the manager's window.pickupPointManagerConfig hook, so it does not have to change
// the shipping methods other specs use; the behaviour was also reproduced with no pickup point shipping method
// in the database.
// https://github.com/Setono/SyliusShipmondoPlugin/issues/22
test.fixme('no pickup points are requested when no shipping method has pickup point delivery', async ({ page }) => {
    const requested = await interceptPickupPoints(page);
    await page.addInitScript(() => {
        (window as unknown as { pickupPointManagerConfig: object }).pickupPointManagerConfig = { allowedShippingMethods: [] };
    });

    await checkoutToShippingStep(page, uniqueEmail());
    await page.waitForLoadState('networkidle');

    expect(requested).toEqual([]);
});
