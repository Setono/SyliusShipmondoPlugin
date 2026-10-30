import { Locator, Page } from '@playwright/test';

/** A Sylius flash message, e.g. flash(page, 'info') */
export function flash(page: Page, type: 'success' | 'error' | 'info'): Locator {
    const cssClass = { success: 'positive', error: 'negative', info: 'info' }[type];

    return page.locator(`.sylius-flash-message.${cssClass}`);
}

/** Clicks a button guarded by Sylius's "Are you sure?" confirmation modal and confirms */
export async function clickAndConfirm(page: Page, button: Locator): Promise<void> {
    await button.click();
    await page.locator('#confirmation-button').click();
}

/**
 * Submits a form with a POST and waits for the page that answers it. Needed where the page posts to its own URL
 * and the old page already looks like the expected result.
 */
export async function submitForm(page: Page, button: Locator): Promise<void> {
    await Promise.all([
        page.waitForResponse((response) => response.request().method() === 'POST' && response.request().resourceType() === 'document'),
        button.click(),
    ]);
    await page.waitForLoadState();
}

/** Picks an option in a Semantic UI dropdown (the admin enhances <select> elements with it) */
export async function pickDropdownOption(dropdown: Locator, option: string): Promise<void> {
    await dropdown.click();
    await dropdown.locator('.menu .item').filter({ hasText: option }).click();
}
