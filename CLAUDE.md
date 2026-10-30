# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a Sylius plugin that integrates Sylius e-commerce stores with Shipmondo shipping service. The plugin:
- Uploads Sylius orders to Shipmondo
- Manages shipments and shipping labels
- Handles pickup point delivery
- Processes Shipmondo webhooks for shipment events
- Tracks order state through a custom workflow

## Development Commands

### Testing
```bash
# Run the unit tests (the default testsuite; no database needed)
composer phpunit

# Run PHPUnit directly
vendor/bin/phpunit

# Run the functional tests (boot the test app against MySQL; see "Functional tests" below)
vendor/bin/phpunit --testsuite Functional

# Run a single test file
vendor/bin/phpunit tests/Unit/DataMapper/ShippingSalesOrderDataMapperTest.php

# Run a single test by name (uses @test annotations, so filter on the method name)
vendor/bin/phpunit --filter it_maps_shipping_with_tax_included_in_price

# Run mutation tests (Infection) — requires a coverage driver (pcov/xdebug)
vendor/bin/infection
```

PHPUnit bootstraps the test app via `tests/Application/config/bootstrap.php` (see `phpunit.xml.dist`). Unit tests live under `tests/Unit/` (e.g. `tests/Unit/DataMapper`, `tests/Unit/Registrar`, `tests/Unit/DependencyInjection`, `tests/Unit/Workflow`, `tests/Unit/Model`), with namespace `Tests\Setono\SyliusShipmondoPlugin\Unit\...`; `tests/Application/` is the Sylius host app, not a test suite. Tests use PHPUnit 9 `@test` annotations, `Prophecy\PhpUnit\ProphecyTrait` for mocks, and `matthiasnoback/symfony-dependency-injection-test` for container/extension tests. Infection enforces `minCoveredMsi: 100`, so any test that covers a line must kill every mutant on it. Infection and code coverage run the `Unit` suite only (`defaultTestSuite` in `phpunit.xml.dist`, `testFrameworkOptions` in `infection.json.dist`).

#### Functional tests

`tests/Functional/` (namespace `Tests\Setono\SyliusShipmondoPlugin\Functional\...`, testsuite `Functional`) boots the test app (`WebTestCase`) against the MySQL database in `DATABASE_URL`.
- **The suite drops and recreates that database's schema once per run.** Locally, point it at a throwaway database in an untracked `tests/Application/.env.test.local`, e.g. `DATABASE_URL=mysql://root@127.0.0.1/setono_sylius_shipmondo_functional_test`. The database is created if it does not exist.
- Isolation is `dama/doctrine-test-bundle` (registered for the `test` env; its PHPUnit extension is in `phpunit.xml.dist`). Every test runs in a transaction that is rolled back afterwards, on top of a baseline committed once per run (`Support\ShopFixtures::createBaseline()`: the shop and its admin, loaded per test via `$this->fixtures`). DAMA requires `use_savepoints: true` (set in `config/packages/test/dama_doctrine_test_bundle.yaml`), so in these tests nested transactions roll back to savepoints instead of marking the outer transaction rollback-only.
- Tests that need real commits or the consumers' DBAL 3 semantics (no savepoints) override `commitsForReal()` to return `true`, as `OrderCancellationTest` does. They get a plain connection without savepoints, read through a second connection (only committed data), and the baseline is restored afterwards.
- The Shipmondo API is never called. `FunctionalTestCase` replaces the `setono_sylius_shipmondo.client` service with a real SDK client whose PSR-18 transport is `Support\ShipmondoApiStub`. Register responses per method and path, e.g. `$this->shipmondo->respond('DELETE', 'sales_orders/123', 204, null)`, and assert on `$this->shipmondo->requests()`. A request without a registered response fails the test.
- `Support\ShopFixtures` creates customers and real orders through Sylius's factories and checkout state machine.
- Assert persisted state via `orderRow()` or `database()`. Run console commands with `executeCommand()` (an `Application` from the kernel plus a `CommandTester`, as in Symfony's "Testing Commands" docs), then `$tester->assertCommandIsSuccessful()`.
- When the frontend is not built, empty Webpack Encore builds are written to `tests/Application/public/build/{admin,shop}` for the run and removed afterwards.
- A test that demonstrates an open bug calls `self::skipUntilFixed(<issue number>)`.

### Playwright tests

Browser tests for the admin and shop UI live in `tests/Playwright/` (own `package.json`, TypeScript, Chromium); see its `README.md`. They run against the test app in the `e2e` environment (`APP_ENV=e2e`, database `setono_sylius_shipmondo_e2e`), where `config/services_e2e.yaml` replaces the Shipmondo SDK's HTTP transport with a stub (`tests/Application/E2E/`), so no credentials or network are needed.
```bash
(cd tests/Playwright && npm ci && npx playwright install chromium)
(cd tests/Playwright && PHP_BINARY=php npm run db:reset)               # schema + Sylius fixtures, e2e database only
(cd tests/Application && APP_ENV=e2e symfony serve -d --no-tls --port=8000)
(cd tests/Playwright && E2E_BASE_URL=http://127.0.0.1:8000 npm test)
```
The test app's assets need Node 20 (`@sylius-ui/frontend` rejects newer versions). Specs showing a known bug are `test.fixme()` with the issue URL.

### Code Quality
```bash
# Run static analysis
composer analyse
# or
vendor/bin/phpstan analyse

# Check code style (ECS / EasyCodingStandard, imports the sylius-labs ruleset)
composer check-style

# Fix code style
composer fix-style

# Run rector (static code analysis)
vendor/bin/rector process --dry-run

# Check dependency declarations (used-but-undeclared / declared-but-unused)
vendor/bin/composer-dependency-analyser
```

Most dev tooling (PHPStan + its strict/phpunit/doctrine/symfony/prophecy extensions, Rector, Infection, PHPUnit, ECS via `sylius-labs/coding-standard`, `ergebnis/composer-normalize` and `sylius/sylius` itself) is pulled in via **`setono/sylius-plugin-pack`**, so it is not listed individually in `require-dev`.

PHPStan runs at `level: max` with strict rules against `src/` and `tests/` (config in `phpstan.neon`), excluding `tests/Application/` and `src/Controller/DebugWebhookController.php`. It boots the test-app kernel (`tests/PHPStan/console_application.php` + `object_manager.php`) for Symfony- and Doctrine-aware analysis, so the kernel must boot for `composer analyse` to run. `phpstan-webmozart-assert` is kept in `require-dev` (not in the pack). Dependency hygiene is checked by `shipmonk/composer-dependency-analyser` (config in `composer-dependency-analyser.php`), which excludes `tests/`; the CI dependency-analysis job unsets `require-dev` first so `src/` is analysed against the split Sylius packages consumers actually install.

### Test Application Setup
```bash
cd tests/Application

# Install frontend dependencies
yarn install
yarn build
bin/console assets:install

# Database setup
bin/console doctrine:database:create
bin/console doctrine:schema:create

# Load fixtures
bin/console sylius:fixtures:load -n

# Start development server
symfony serve -d
```

### Plugin Commands
```bash
# Upload orders to Shipmondo
(cd tests/Application && bin/console setono:sylius-shipmondo:upload-orders)

# Register webhooks with Shipmondo
(cd tests/Application && bin/console setono:sylius-shipmondo:register-webhooks)
```

### Linting
```bash
cd tests/Application

# Lint YAML files
bin/console lint:yaml ../../src/Resources

# Lint Twig files
bin/console lint:twig ../../src/Resources
```

### CI checks

CI (`.github/workflows/build.yaml`) gates merges on the following — run them locally before pushing to avoid red builds:
- `composer validate --strict` and `composer normalize --dry-run`
- `composer check-style` and `vendor/bin/rector process --dry-run`
- `composer analyse` (PHPStan; static-analysis job removes `sylius/sylius` first, so analysis runs against the split component packages)
- `vendor/bin/composer-dependency-analyser` (dependency-analysis job unsets `require-dev`, re-adds shipmonk, then analyses)
- `lint:yaml` / `lint:twig` and `bin/console lint:container`
- `vendor/bin/phpunit` (unit) and `vendor/bin/infection` (mutation tests); these jobs have no database
- `vendor/bin/phpunit --testsuite Functional`, in the integration-tests job, which starts MySQL

The matrix runs **PHP 8.1, 8.2 and 8.3** on **Symfony 6.4** (the plugin requires `symfony/webhook` + `symfony/remote-event`, so Symfony 5.4 is not supported). Coding-standards runs on PHP 8.1 — write syntax compatible with 8.1, not just the locally linked PHP.

## Architecture

### Core Components

**Data Mapping System**
- Located in `src/DataMapper/`
- Uses composite pattern via `CompositeSalesOrderDataMapper`
- Individual mappers (registered via `SalesOrderDataMapperInterface` tag) handle specific aspects:
  - `SalesOrderDataMapper`: Basic order data and addresses
  - `OrderLinesSalesOrderDataMapper`: Order line items
  - `ShippingSalesOrderDataMapper`: Shipping information
  - `PaymentDetailsSalesOrderDataMapper`: Payment details
  - `ShipmentTemplateSalesOrderDataMapper`: Shipment templates
  - `ServicePointDataMapper`: Pickup point data
- New mappers can be added by implementing `SalesOrderDataMapperInterface` and will be auto-registered via the `setono_sylius_shipmondo.sales_order_data_mapper` tag

**Order Workflow**
- Defined in `src/Workflow/OrderWorkflow.php`
- States: pending → uploading_to_shipmondo → uploaded_to_shipmondo (or failed)
- Transitions: `start_upload`, `complete_upload`, `fail`, `reset` (uploaded_to_shipmondo → pending, when the sales order is deleted in Shipmondo)
- Workflow name: `setono_sylius_shipmondo__order`
- State stored in Order entity's `shipmondoState` property

**Message Bus Architecture**
- Commands: `UploadOrder`, `DeleteOrder`, `RegisterWebhooks` in `src/Message/Command/`
- Handlers: Located in `src/Message/CommandHandler/`
- Uses dedicated command bus: `setono_sylius_shipmondo.command_bus`
- Bus middleware: doctrine_transaction, router_context

**Webhook Handling**
- Symfony webhook component integration
- Webhook routing identifier: `shipmondo`
- Custom parser: `setono_sylius_shipmondo.webhook.webhook_parser`, a thin adapter over the SDK's `WebhookParser` (verifies the HS256 signature, reads the `SMD-*` headers, unwraps the `data` envelope) that yields a `Webhook\RemoteEvent`
- `WebhookConsumer` passes each event to `CompositeRemoteEventHandler`, which runs every `RemoteEventHandlerInterface` (auto-tagged `setono_sylius_shipmondo.remote_event_handler`): `FulfillOrderHandler`, `PaymentStateHandler`, `ResetUploadStateHandler` in `src/Webhook/Handler/`
- `OrderResolver` links a payload to its Sylius order via `shipmondoId`, falling back to the order number

**Order Cancellation**
- `OrderCancellationListener` dispatches `DeleteOrder` when a Sylius order is cancelled, hooked on both state-machine backends (a winzou callback prepended by the extension, and a Symfony Workflow listener)
- `DeleteOrderHandler` must not throw on Shipmondo failures: handled synchronously it runs inside Sylius's cancel transaction, and the bus's `doctrine_transaction` rollback would make the cancel fail

### Entity Extensions

The plugin extends these Sylius entities via traits:
- **Order**: `OrderTrait` - adds Shipmondo state, external ID, pickup point data
- **Shipment**: `ShipmentTrait` - adds Shipmondo ID, package/parcel numbers, label URL
- **ShippingMethod**: `ShippingMethodTrait` - adds pickup point delivery flag and carrier code
- **PaymentMethod**: `PaymentMethodTrait` - adds Shipmondo payment name

### Key Services

- `OrderUploaderInterface`: Orchestrates order upload to Shipmondo
- `SalesOrderDataMapperInterface`: Maps Sylius orders to Shipmondo API format
- Registrars in `src/Registrar/`: Handle webhook registration

### Configuration

- Plugin must be registered **before** `SyliusGridBundle` in `bundles.php`
- Environment variables required: `SHIPMONDO_USERNAME`, `SHIPMONDO_KEY`, `SHIPMONDO_WEBHOOKS_KEY`, `SHIPMONDO_SANDBOX` (bool; selects the production vs. sandbox API — the `api.sandbox` option defaults to `%env(bool:SHIPMONDO_SANDBOX)%`)
- Routes: Use `routes.yaml` for locale-aware apps, `routes_no_locale.yaml` otherwise
- Config location: `src/Resources/config/`

### Frontend Integration

- Admin UI: JavaScript enhancements for shipping method forms
- Shop UI: JavaScript for pickup point selection
- Twig templates: `src/Resources/views/`
- Event hooks: Injects JavaScript via Sylius UI events

## Important Notes

- The plugin uses Shipmondo PHP SDK v2 (`setono/shipmondo-php-sdk:^2.0`). See `UPGRADE.md` for the 1.x → 2.x consumer upgrade.
- **Always keep `UPGRADE.md` up to date.** Whenever a change affects how consumers integrate or upgrade the plugin — a BC break, a renamed/removed/added public class, interface or service signature, a new required environment variable or config option, or a behavioural change — document it in `UPGRADE.md` (under the relevant version section) as part of the *same* change.
- PHP 8.1+ required (kept 8.1-compatible; the test app is verified on 8.1/8.2/8.3)
- Symfony 6.4 required (the plugin uses `symfony/webhook` + `symfony/remote-event`, so Symfony 5.4 is not supported)
- Compatible with Sylius ~1.14 (the test app tracks the SyliusPluginSkeleton 1.14.x)

## Testing Strategy

Unit tests live in `tests/Unit/` and functional tests in `tests/Functional/`, with a full Sylius test application in `tests/Application/`.
The test app includes custom entity implementations in `tests/Application/Model/`.

## Agent skills

### Issue tracker

Issues and specs live in GitHub Issues on `Setono/SyliusShipmondoPlugin`, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five default triage labels are used as-is (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root, created only when a term or decision gets settled. See `docs/agents/domain.md`.
