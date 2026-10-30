import { randomInt } from 'node:crypto';
import { expect, Page, test } from '@playwright/test';
import { pickupPointShippingMethod, regularShippingMethod } from '../../support/config';
import { findPickupPointOfLatestOrder } from '../../support/db';
import { pickupPoints, StubLog } from '../../support/shipmondo-stub';
import {
    checkoutToShippingStep,
    germanAddress,
    PICKUP_POINTS_ENDPOINT,
    pickupPointList,
    pickupPointOption,
    pickupPointRadios,
    selectShippingMethod,
    shippingMethodRadio,
    uniqueEmail,
} from '../../support/shop';

/*
 * Pickup point delivery through the real checkout: the browser calls the plugin's real pickup point endpoint
 * (GetPickupPointsAction), which searches pickup points through the Shipmondo SDK; only the SDK's HTTP
 * transport is stubbed, answering with pickup points captured from the Shipmondo API.
 */

const [firstPoint, secondPoint] = pickupPoints;

async function continueToPaymentStep(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page).toHaveURL(/\/en_US\/checkout\/select-payment$/);
}

async function backToShippingStep(page: Page): Promise<void> {
    await page.getByRole('link', { name: 'Change shipping method' }).click();
    await expect(page).toHaveURL(/\/en_US\/checkout\/select-shipping$/);
}

test('pickup points are searched near the shipping address with the carrier of the shipping method', async ({ page }) => {
    const street = `Unter den Linden ${randomInt(1, 1_000_000)}`;
    const log = StubLog.mark();
    const response = page.waitForResponse((candidate) => PICKUP_POINTS_ENDPOINT.test(candidate.url()));

    await checkoutToShippingStep(page, uniqueEmail(), { street });

    expect((await response).status()).toBe(200);
    const searches = log.requests('GET', /\/pickup_points$/).filter((entry) => entry.query.address === street);
    expect(searches).toHaveLength(1);
    expect(searches[0].query).toEqual({
        carrier_code: pickupPointShippingMethod.carrierCode,
        country_code: germanAddress.countryCode,
        zipcode: germanAddress.postcode,
        address: street,
    });
});

test('the chosen pickup point is kept through the checkout and saved on the order', async ({ page }) => {
    const email = uniqueEmail();
    await checkoutToShippingStep(page, email);

    await selectShippingMethod(page, pickupPointShippingMethod.name);
    await expect(pickupPointRadios(page)).toHaveCount(pickupPoints.length);
    await expect(pickupPointRadios(page).first()).toBeChecked();
    await pickupPointOption(page, secondPoint.name).locator('input[type="radio"]').check();
    await continueToPaymentStep(page);

    // Back on the shipping step the chosen pickup point is listed first and preselected
    await backToShippingStep(page);
    await expect(shippingMethodRadio(page, pickupPointShippingMethod.code)).toBeChecked();
    await expect(pickupPointRadios(page)).toHaveCount(pickupPoints.length);
    await expect(pickupPointList(page).locator('.ui.grid').first()).toContainText(secondPoint.name);
    await expect(pickupPointRadios(page).first()).toBeChecked();
    await continueToPaymentStep(page);

    await page.getByRole('button', { name: 'Next' }).click();
    await expect(page).toHaveURL(/\/en_US\/checkout\/complete$/);
    await page.getByRole('button', { name: 'Place order' }).click();
    await expect(page).toHaveURL(/\/en_US\/order\/thank-you$/);

    const { orderState, pickupPoint } = await findPickupPointOfLatestOrder(email);
    expect(orderState).toBe('new');
    expect(pickupPoint).toMatchObject({
        id: secondPoint.id,
        name: secondPoint.name,
        address: secondPoint.address,
        zipcode: secondPoint.zipcode,
        city: secondPoint.city,
        country: secondPoint.country,
    });
});

test('the pickup point is forgotten when the customer switches to a shipping method without pickup points', async ({ page }) => {
    const email = uniqueEmail();
    await checkoutToShippingStep(page, email);

    await selectShippingMethod(page, pickupPointShippingMethod.name);
    await expect(pickupPointRadios(page)).toHaveCount(pickupPoints.length);
    await continueToPaymentStep(page);
    expect((await findPickupPointOfLatestOrder(email)).pickupPoint).toMatchObject({ id: firstPoint.id });

    await backToShippingStep(page);
    // Wait until the preselected pickup point method's pickup points are shown: switching before they load
    // still saves a pickup point (https://github.com/Setono/SyliusShipmondoPlugin/issues/27)
    await expect(pickupPointRadios(page)).toHaveCount(pickupPoints.length);
    await selectShippingMethod(page, regularShippingMethod.name);
    await expect(pickupPointList(page)).toHaveCount(0);
    await continueToPaymentStep(page);

    expect(await findPickupPointOfLatestOrder(email)).toEqual({ orderState: 'cart', pickupPoint: null });
});
