import { baseURL } from './support/config';
import { arrangeShippingMethods } from './support/db';

/**
 * Refuses to run unless the app runs in the `e2e` environment, where the Shipmondo API is stubbed.
 * Against a dev server with real credentials the suite would e.g. register webhooks in a real Shipmondo account.
 * Then arranges the fixture data every spec relies on.
 */
export default async function globalSetup(): Promise<void> {
    let response: Response;

    try {
        response = await fetch(`${baseURL}/en_US/`, { redirect: 'manual' });
    } catch (error) {
        throw new Error(`The test application is not reachable at ${baseURL} (${String(error)}). See tests/Playwright/README.md for how to start it.`);
    }

    if (response.headers.get('x-shipmondo-stub') !== 'active') {
        throw new Error(
            `The application at ${baseURL} does not run in the e2e environment, so its Shipmondo API calls are not stubbed. ` +
                'Start it with APP_ENV=e2e, see tests/Playwright/README.md.',
        );
    }

    await arrangeShippingMethods();
}
