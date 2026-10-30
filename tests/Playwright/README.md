# Playwright end-to-end tests

Browser tests for the plugin's UI in the admin and the shop, run with [Playwright](https://playwright.dev) (TypeScript, Chromium)
against the Sylius test application in `tests/Application`.

## What is real and what is stubbed

The tests drive the real test application: real Sylius, the real plugin (controllers, templates, form extensions,
JavaScript, state machine listeners), the real Shipmondo PHP SDK, a real MySQL database with the Sylius fixtures.

The Shipmondo API is not called. The app runs in a dedicated `e2e` environment (`APP_ENV=e2e`), where
`tests/Application/config/services_e2e.yaml` gives the Shipmondo client a stub HTTP transport
(`tests/Application/E2E/StubShipmondoHttpClient.php`). It answers with the JSON in `tests/Application/E2E/shipmondo/`:
the pickup points, shipment templates and webhooks were captured from the Shipmondo API (they are the
[SDK's test fixtures](https://github.com/Setono/shipmondo-php-sdk/tree/v2.0.0-beta.1/tests/Fixtures)), the payment gateways
are made up to match the SDK's model. The stub also logs every request to `tests/Application/var/log/shipmondo-stub.log`,
so specs can assert what the plugin sent to Shipmondo.

A spec chooses a scenario with the `shipmondo_stub` cookie (`empty`: no gateways/templates/pickup points,
`unauthorized`: every call fails with HTTP 401). `DELETE /sales_orders/{id}` answers by the id: ids starting with
`404` return 404, ids starting with `500` return 500, any other id succeeds.

Besides that:

- `specs/shop/pickup-point-selection.spec.ts` answers the plugin's pickup point endpoint in the browser
  (`page.route`) to test the pickup point JavaScript in isolation. `pickup-point-checkout.spec.ts` uses the real endpoint.
- Some state is arranged in the database directly, because the UI can't create it: e.g. an order that was uploaded
  to Shipmondo (uploading is a background command) gets a Shipmondo id, and `dhl_express` is made a GLS pickup point
  shipping method.

The suite refuses to run when the app doesn't answer with the `X-Shipmondo-Stub` header the `e2e` environment
adds, so it can't accidentally talk to the real Shipmondo API through a dev server with real credentials.

## Specs

| Spec | Covers |
| --- | --- |
| `specs/admin/shipping-method-form.spec.ts` | Pickup point delivery checkbox and carrier select on the shipping method form, the injected `carrier.js` show/hide, the carrier validation, the grid column |
| `specs/admin/shipmondo-page.spec.ts` | Configuration > Shipmondo: menu entry, payment method and shipment template mappings, webhook registration, API error and empty states |
| `specs/admin/order-grid.spec.ts` | The "Shipmondo state" column of the order grid |
| `specs/admin/order-cancellation.spec.ts` | Cancelling an order deletes its Shipmondo sales order and flashes the outcome (deleted, already gone, failed, never uploaded) |
| `specs/shop/pickup-point-selection.spec.ts` | Pickup point list on the shipping step: which methods load points, rendering, switching methods, a failing endpoint |
| `specs/shop/pickup-point-checkout.spec.ts` | Real endpoint: the search parameters sent to Shipmondo, the chosen point surviving the checkout and being saved on the order, switching away from pickup point delivery |
| `specs/global/debug-webhook.spec.ts` | The webhook debugger is closed outside the dev environment |

Specs that demonstrate a known bug are marked `test.fixme()` and link the GitHub issue.

## Setup

Requirements: PHP 8.1 (with the extensions Sylius needs), Composer, MySQL/MariaDB, Node 20 for the test app's
assets (`@sylius-ui/frontend` doesn't support newer Node versions), Node 20+ for Playwright, and the Symfony CLI
(or any web server for `tests/Application/public`).

```bash
# PHP dependencies (repository root)
composer install

# Test app assets
(cd tests/Application && yarn install && yarn build)
(cd tests/Application && APP_ENV=e2e bin/console assets:install --symlink --relative public)

# Playwright
(cd tests/Playwright && npm ci && npx playwright install chromium)
```

### Database

The `e2e` environment uses its own database, `setono_sylius_shipmondo_e2e` as `root` without a password on
`127.0.0.1` (see `tests/Application/.env.e2e`). Override it in an untracked `tests/Application/.env.e2e.local`
or with a `DATABASE_URL` environment variable, and tell the tests where it is with `E2E_DATABASE_URL`.

```bash
cd tests/Playwright
PHP_BINARY=php npm run db:reset   # creates the schema and loads the Sylius fixtures, ~10 seconds
```

`db:reset` only ever touches the `e2e` environment's database, and refuses to run when the database name doesn't
contain `e2e`. Reset before a test run: the specs cancel fixture orders, and a database has enough of them for a
few runs (the suite tells you when it runs out).

### Server

```bash
cd tests/Application
APP_ENV=e2e symfony serve -d --no-tls --port=8000
```

`APP_ENV=e2e` is what switches on the stubbed Shipmondo API; the Symfony CLI passes it on to PHP. Stop the server
with `symfony server:stop`.

## Running

```bash
cd tests/Playwright
npm test                          # headless
npm run test:headed               # watch it
npx playwright test specs/shop    # a directory or file
npm run report                    # the HTML report of the last run
```

| Environment variable | Default | |
| --- | --- | --- |
| `E2E_BASE_URL` | `http://127.0.0.1:8000` | Where the test app runs |
| `E2E_DATABASE_URL` | `mysql://root@127.0.0.1:3306/setono_sylius_shipmondo_e2e` | The e2e database |
| `E2E_SHIPMONDO_STUB_LOG` | `../Application/var/log/shipmondo-stub.log` | The stub's request log |
| `PHP_BINARY` | `php` | PHP used by `npm run db:reset` |

Admin specs import `test` from `support/admin-test.ts`, which logs in as the fixture admin (`sylius`/`sylius`) once
per worker. Each worker needs its own session: flash messages live in the session, so a shared one would let one
worker's page show (and consume) another worker's flash messages. `global-setup.ts` makes `ups` and `dhl_express`
available worldwide (the fixtures pick their zones at random) and `dhl_express` a GLS pickup point shipping method.

Spec files run in parallel (2 workers locally, 1 on CI), the tests within a file one after the other; there are no
retries, so a flaky test fails.
