# Playwright tests

Browser tests for the plugin's UI in the admin and the shop: a real Chromium, driven through
[playwright-php/playwright-symfony](https://github.com/playwright-php/playwright-symfony), runs the pages with their
JavaScript, and every request it makes to `http://localhost` is handled in the PHPUnit process by the test's kernel.
No web server, no separate database.

| Test | Covers |
| --- | --- |
| `Admin/ShippingMethodFormTest` | The pickup point delivery checkbox and carrier select on the shipping method form, `carrier.js` showing the select only while the checkbox is checked, the carrier validation, the grid column |
| `Admin/ShipmondoPageTest` | Configuration > Shipmondo: the menu entry, mapping payment methods and shipment templates, registering the webhooks, the API error and empty states |
| `Admin/OrderGridTest` | The "Shipmondo state" column of the order grid |
| `Admin/OrderCancellationTest` | Cancelling an order deletes its Shipmondo sales order, and the flash message for each outcome |
| `Shop/PickupPointSelectionTest` | `pickup-point-manager.js` on the shipping step: which methods it asks pickup points for, the list, switching methods, a failing endpoint |
| `Shop/PickupPointCheckoutTest` | The search sent to Shipmondo, the chosen pickup point surviving the checkout and being saved on the order, switching away from it |

Tests that demonstrate an open bug call `self::skipUntilFixed(<issue>)`.

## What is real and what is stubbed

`BrowserTestCase` uses the functional suite's setup (`tests/Functional/Support`): the baseline shop built through Sylius's
factories (`ShopFixtures`), every test inside a transaction that dama/doctrine-test-bundle rolls back, and the Shipmondo
API stubbed at the HTTP boundary (`ShipmondoApiStub`): the plugin and the Shipmondo SDK run for real, nothing is sent to
Shipmondo, and a request the test did not stub fails it. The stub's pickup points, shipment templates and webhooks were
captured from the Shipmondo API (`Support/shipmondo`, from the
[SDK's fixtures](https://github.com/Setono/shipmondo-php-sdk/tree/v2.0.0-beta.1/tests/Fixtures)); the payment gateways
are modelled on the SDK's DTO.

Only one test changes what the browser receives: the race in `PickupPointSelectionTest` holds the pickup point response
back in the page (by wrapping `fetch`) until the test has switched shipping methods.

Two things `BrowserTestCase` does that a test may run into:

- It reboots the kernel before every request after the first, as Symfony's `KernelBrowser` does, and installs the stub
  in the new container. A reused container leaks state between requests (adding to the cart fails with "A new entity
  was found through the relationship Order#channel"). Entities a test loaded are detached after the first request, so
  set up data before visiting and assert through the database (`connection()`).
- After a navigation it waits for the page to load with quick `evaluate()` calls only (`visit()`,
  `clickAndFollowRedirects()`, `waitUntil()`, `settle()`). The Playwright server runs the commands it reads together
  one after the other, so a command that waits for the page could otherwise sit in front of the answer to a request
  the page still waits for, and both wait 30 seconds. Use `clickAndFollowRedirects()` for clicks that navigate: it
  also follows a redirect after a form POST, which playwright-php otherwise only does after the click times out.

## Running

`playwright-php/playwright-symfony` requires PHP >= 8.2 and the plugin supports 8.1, so the package is not in
`composer.json` (CI installs it in the `browser-tests` job). Locally, on PHP 8.2 and with Node 20:

```bash
php8.2 composer require --dev --no-update "playwright-php/playwright-symfony:^0.11"
php8.2 composer update
php8.2 vendor/bin/playwright-install chromium

# the pages need the test application's assets; copies, because the asset server does not follow symlinks
(cd tests/Application && yarn install && yarn build && bin/console assets:install public)

php8.2 vendor/bin/phpunit --testsuite Playwright
```

where `php8.2` is your PHP 8.2 binary, e.g. `/opt/homebrew/opt/php@8.2/bin/php`. Revert `composer.json` afterwards
(`git checkout composer.json`) and reinstall on PHP 8.1 before running the other tools.

The database is the functional suite's: `DATABASE_URL` of the `test` environment, e.g. in an untracked
`tests/Application/.env.test.local`. Its schema is recreated once per run.

`PLAYWRIGHT_HEADLESS=false` shows the browser.
