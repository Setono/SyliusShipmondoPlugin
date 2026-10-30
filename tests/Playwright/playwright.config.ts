import { defineConfig, devices } from '@playwright/test';
import { baseURL } from './support/config';

export default defineConfig({
    testDir: './specs',
    globalSetup: './global-setup.ts',
    // Sylius pages are slow in debug mode, the checkout specs walk several of them
    timeout: 90_000,
    expect: { timeout: 10_000 },
    // Specs within a file share database state (e.g. the admin Shipmondo page mappings), so files run in parallel but their tests don't
    fullyParallel: false,
    workers: process.env.CI ? 1 : 2,
    // No retries: a flaky spec should fail, not hide
    retries: 0,
    forbidOnly: !!process.env.CI,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        ...devices['Desktop Chrome'],
        baseURL,
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            // admin specs log in through support/admin-test.ts
            name: 'admin',
            testMatch: /admin\/.*\.spec\.ts/,
        },
        {
            name: 'shop',
            testMatch: /(shop|global)\/.*\.spec\.ts/,
        },
    ],
});
