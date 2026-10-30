import path from 'node:path';

/** Where the test application runs, in the `e2e` environment (APP_ENV=e2e) */
export const baseURL = (process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, '');

/** The database of the `e2e` environment, see tests/Application/.env.e2e */
export const databaseUrl = process.env.E2E_DATABASE_URL ?? 'mysql://root@127.0.0.1:3306/setono_sylius_shipmondo_e2e';

/** The test application, used to read the Shipmondo stub's responses and request log */
export const applicationDir = path.resolve(__dirname, '../../Application');

/** Every request the stubbed Shipmondo API receives is appended here, see tests/Application/E2E/StubShipmondoHttpClient.php */
export const stubLogFile = process.env.E2E_SHIPMONDO_STUB_LOG ?? path.join(applicationDir, 'var/log/shipmondo-stub.log');

/** Where the logged-in admin sessions are stored, one per worker (see support/admin-test.ts) */
export const authDir = path.resolve(__dirname, '../.auth');

/** The fixture admin, see the Sylius default fixture suite */
export const admin = { username: 'sylius', password: 'sylius' };

/** The fixture shipping method the suite configures for pickup point delivery (see global-setup.ts) */
export const pickupPointShippingMethod = { code: 'dhl_express', name: 'DHL Express', carrierCode: 'gls' };

/** A fixture shipping method without pickup point delivery (see global-setup.ts) */
export const regularShippingMethod = { code: 'ups', name: 'UPS' };
