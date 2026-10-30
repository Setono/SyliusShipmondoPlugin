import { expect, test } from '@playwright/test';

/*
 * The plugin's webhook debugger (/shipmondo/debug-webhook) decodes webhook payloads with the webhooks key,
 * so it must only work in the dev environment. The suite runs in the e2e environment.
 */

test('the webhook debugger is not available outside the dev environment', async ({ page, request }) => {
    const response = await page.goto('/shipmondo/debug-webhook');
    expect(response?.status()).toBe(403);

    const decode = await request.post('/shipmondo/debug-webhook/decode', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        data: { data: 'eyJhbGciOiJIUzI1NiJ9.e30.ZRrHA1JJJW8opsbCGfG_HACGpVUMN_a9IV7pAx_Zmeo' },
    });
    expect(decode.status()).toBe(403);
});
