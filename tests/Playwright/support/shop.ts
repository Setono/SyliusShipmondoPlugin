import { randomUUID } from 'node:crypto';
import { Locator, Page, Route } from '@playwright/test';
import { findProductSlug } from './db';
import { pickupPoints } from './shipmondo-stub';

export const PICKUP_POINTS_ENDPOINT = /\/ajax\/get-pickup-points(\?|$)/;

export function uniqueEmail(): string {
    return `e2e-${randomUUID()}@example.com`;
}

export const germanAddress = {
    firstName: 'Erika',
    lastName: 'Mustermann',
    street: 'Unter den Linden 1',
    countryCode: 'DE',
    city: 'Berlin',
    postcode: '10117',
};

/**
 * Puts a product in a new cart and completes the address step as a guest, ending on the shipping step
 */
export async function checkoutToShippingStep(page: Page, email: string, address: Partial<typeof germanAddress> = {}): Promise<void> {
    const { firstName, lastName, street, countryCode, city, postcode } = { ...germanAddress, ...address };

    await page.goto(`/en_US/products/${await findProductSlug()}`);
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.waitForURL('**/en_US/cart/');
    await page.getByRole('button', { name: 'Checkout' }).click();
    await page.waitForURL('**/en_US/checkout/address');

    await page.getByLabel('Email').fill(email);
    await page.locator('#sylius_checkout_address_billingAddress_firstName').fill(firstName);
    await page.locator('#sylius_checkout_address_billingAddress_lastName').fill(lastName);
    await page.locator('#sylius_checkout_address_billingAddress_street').fill(street);
    await page.locator('#sylius_checkout_address_billingAddress_countryCode').selectOption(countryCode);
    await page.locator('#sylius_checkout_address_billingAddress_city').fill(city);
    await page.locator('#sylius_checkout_address_billingAddress_postcode').fill(postcode);
    await page.getByRole('button', { name: 'Next' }).click();
    await page.waitForURL('**/en_US/checkout/select-shipping');
}

export function shippingMethods(page: Page): Locator {
    return page.locator('#sylius-shipping-methods');
}

/** Selects a shipping method the way a customer does: by clicking its name */
export async function selectShippingMethod(page: Page, name: string): Promise<void> {
    await shippingMethods(page).locator('label').filter({ hasText: new RegExp(`^${name}$`) }).click();
}

export function shippingMethodRadio(page: Page, code: string): Locator {
    return shippingMethods(page).locator(`input[type="radio"][name$="[method]"][value="${code}"]`);
}

/** The pickup point list the plugin inserts below the selected shipping method */
export function pickupPointList(page: Page): Locator {
    return page.locator('.pickup-points-container');
}

export function pickupPointRadios(page: Page): Locator {
    return pickupPointList(page).locator('input[type="radio"]');
}

/** The pickup point option (radio, address and opening hours) showing the given pickup point name */
export function pickupPointOption(page: Page, name: string): Locator {
    return pickupPointList(page).locator('.ui.grid').filter({ hasText: name });
}

function escapeHtml(value: string): string {
    return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/**
 * A response like the one GetPickupPointsAction returns, for specs that intercept the endpoint in the
 * browser. The HTML has the structure of src/Resources/views/shop/ajax/pickup_points.html.twig, including
 * the %fieldName% placeholder the JavaScript replaces and the pre-checked first pickup point.
 */
export function pickupPointsResponse(points = pickupPoints): { pickupPoints: unknown[]; html: string } {
    const mapped = points.map((point) => ({
        raw: point,
        id: point.id,
        number: point.number,
        companyName: point.company_name,
        name: point.name,
        address: point.address,
        zipcode: point.zipcode,
        city: point.city,
        country: point.country,
        openingHours: point.opening_hours,
        address2: point.address2,
    }));

    const options = mapped
        .map(
            (point, index) => `
            <div class="ui grid" style="margin-bottom: 20px; padding: 20px; background-color: #F3F4F5">
                <div class="one wide column">
                    <input type="radio" name="%fieldName%" value="${escapeHtml(JSON.stringify(point))}"${index === 0 ? ' checked' : ''}>
                </div>
                <div class="eight wide column">
                    <h4>Delivery address</h4>
                    <div style="font-size: 12px">${escapeHtml(point.name)}<br>${escapeHtml(point.address)}<br>${escapeHtml(point.zipcode)} ${escapeHtml(point.city)}</div>
                </div>
                <div class="seven wide column" style="text-align: right">
                    <h4>Opening hours</h4>
                    <div style="font-size: 12px">${point.openingHours.map((hours) => `<div>${escapeHtml(hours)}</div>`).join('')}</div>
                </div>
            </div>`,
        )
        .join('');

    return {
        pickupPoints: mapped,
        html: `<div class="item pickup-points-container"><div class="field" style="width: 17px">&nbsp;</div><div class="content">${options}</div></div>`,
    };
}

/**
 * Answers the shop's pickup point endpoint in the browser, and records which shipping methods were requested
 */
export async function interceptPickupPoints(page: Page, answer: (route: Route) => Promise<void> = (route) => route.fulfill({ json: pickupPointsResponse() })): Promise<string[]> {
    const requestedShippingMethods: string[] = [];

    await page.route(PICKUP_POINTS_ENDPOINT, async (route) => {
        requestedShippingMethods.push(new URL(route.request().url()).searchParams.get('shippingMethod') ?? '');
        await answer(route);
    });

    return requestedShippingMethods;
}
