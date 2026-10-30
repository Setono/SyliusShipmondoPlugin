import fs from 'node:fs';
import path from 'node:path';
import { expect, test as base } from '@playwright/test';
import { admin, authDir, baseURL } from './config';

/**
 * `test` for admin specs: every worker logs in as the fixture admin once and its tests reuse that session.
 *
 * Each worker needs a session of its own. Flash messages live in the server-side session, so with one shared
 * session a page loaded by one worker would show (and consume) the flash messages of another worker's test.
 */
export const test = base.extend<object, { adminStorageState: string }>({
    storageState: ({ adminStorageState }, use) => use(adminStorageState),

    adminStorageState: [
        async ({ browser }, use) => {
            const file = path.join(authDir, `admin-${test.info().parallelIndex}.json`);
            fs.mkdirSync(authDir, { recursive: true });

            const context = await browser.newContext({ baseURL, storageState: undefined });
            const page = await context.newPage();
            await page.goto('/admin/login');
            await page.getByLabel('Username').fill(admin.username);
            await page.getByLabel('Password').fill(admin.password);
            await page.getByRole('button', { name: 'Login' }).click();
            await expect(page).toHaveURL(/\/admin\/$/);
            await context.storageState({ path: file });
            await context.close();

            await use(file);
        },
        { scope: 'worker' },
    ],
});

export { expect };
