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

PHPUnit bootstraps the test app via `tests/Application/config/bootstrap.php` (see `phpunit.xml.dist`). Unit tests live under `tests/Unit/` (e.g. `tests/Unit/DataMapper`, `tests/Unit/Registrar`, `tests/Unit/DependencyInjection`, `tests/Unit/Workflow`, `tests/Unit/Model`), with namespace `Tests\Setono\SyliusShipmondoPlugin\Unit\...`; `tests/Application/` is the Sylius host app, not a test suite. Tests use PHPUnit 9 `@test` annotations, `Prophecy\PhpUnit\ProphecyTrait` for mocks, and `matthiasnoback/symfony-dependency-injection-test` for container/extension tests. Code that calls Shipmondo is unit-tested against the real SDK over scripted HTTP answers (`ShipmondoApi::client(new ScriptedHttpClient())`), not a mocked client; assert on logs with `RecordingLogger`. Test helpers both suites share live in `tests/Support/`. Infection enforces `minCoveredMsi: 100`, so any test that covers a line must kill every mutant on it. Infection runs the `Unit` suite only (`defaultTestSuite` in `phpunit.xml.dist`, `testFrameworkOptions` in `infection.json.dist`). Code coverage (CI's code-coverage job, uploaded to Codecov) counts the `Unit` and `Functional` suites: the job starts MySQL and uploads a clover report per suite, so code only the functional tests reach (e.g. `RetryUploadController`) counts as covered, though Infection doesn't mutate it.

#### Functional tests

`tests/Functional/` (namespace `Tests\Setono\SyliusShipmondoPlugin\Functional\...`, testsuite `Functional`) boots the test app (`WebTestCase`) against the MySQL database in `DATABASE_URL`.
- **The suite drops and recreates that database's schema once per run.** Locally, point it at a throwaway database in an untracked `tests/Application/.env.test.local`, e.g. `DATABASE_URL=mysql://root@127.0.0.1/setono_sylius_shipmondo_functional_test`. The database is created if it does not exist (`TestApplication::prepare()` runs `doctrine:database:create --if-not-exists` before anything connects to it, outside DAMA's static connections).
- Isolation is `dama/doctrine-test-bundle` (registered for the `test` env; its PHPUnit extension is in `phpunit.xml.dist`). Every test runs in a transaction that is rolled back afterwards, on top of a baseline committed once per run (`Support\ShopFixtures::createBaseline()`: the shop and its admin, loaded per test via `$this->fixtures`). DAMA requires `use_savepoints: true` (set in `config/packages/test/dama_doctrine_test_bundle.yaml`), so in these tests nested transactions roll back to savepoints instead of marking the outer transaction rollback-only.
- Tests that need real commits or the consumers' DBAL 3 semantics (no savepoints) override `commitsForReal()` to return `true`, as `OrderCancellationTest` does. They get a plain connection without savepoints, read through a second connection (only committed data), and the baseline is restored afterwards.
- The Shipmondo API is never called. `FunctionalTestCase` replaces the PSR-18 versions of the plugin's two HTTP clients (`psr18.setono_sylius_shipmondo.http_client` and `psr18.setono_sylius_shipmondo.pickup_points_http_client`, see Configuration) with `Support\ShipmondoApiStub` (`install()`), so the container's real SDK client sends its requests to it, through `HttpClient\ShipmondoHttpClient`. Register responses per method and path, e.g. `$this->shipmondo->respond('DELETE', 'sales_orders/123', 204, null)`, or a network error with `failToConnect('POST', 'sales_orders')`, and assert on `$this->shipmondo->requests()`; `httpClientOf($request)` names the HTTP client that sent a request. A request without a registered response fails the test.
- In the test environment every Symfony HTTP client's transport is `Support\HttpTransportStub` (`framework.http_client.mock_response_factory`): it fails every request as an unreachable network does (a transport error, so e.g. Sylius's admin version check degrades as it would offline), unless a test calls `answerWith()`, and records the options each request was sent with. `HttpClient\ShipmondoHttpClientsTest` checks the time limits of the plugin's HTTP clients that way.
- `Support\ShopFixtures` creates customers and real orders through Sylius's factories and checkout state machine.
- Assert on what the application logged via `logs()` (the test app's `recorded` Monolog handler). An upload fetches the payment gateways, since the baseline's payment method is mapped: stub them with `respondWithThePaymentGateway()`.
- Assert persisted state via `orderRow()` or `database()`. Run console commands with `executeCommand()` (an `Application` from the kernel plus a `CommandTester`, as in Symfony's "Testing Commands" docs), then `$tester->assertCommandIsSuccessful()`.
- When the frontend is not built, empty Webpack Encore builds are written to `tests/Application/public/build/{admin,shop}` for the run and removed afterwards.
- A test that demonstrates an open bug calls `self::skipUntilFixed(<issue number>)`.

#### Playwright tests

`tests/Playwright/` (testsuite `Playwright`, see its `README.md`) drives the admin and shop UI in a real Chromium with `playwright-php/playwright-symfony`, which handles the browser's requests in-process with the test's kernel. It reuses the functional suite's setup (`ShopFixtures`, DAMA, `ShipmondoApiStub`); `BrowserTestCase` reboots the kernel between requests and re-installs the stub.
- The package requires PHP >= 8.2, so it is **not** in `composer.json`: CI installs it in the `browser-tests` job only, and the bundle (`config/bundles.php`) and its config (`config/packages/test/playwright.php`) are only loaded when it is installed. PHPStan and Rector skip `tests/Playwright/`.
- Run it locally on PHP 8.2, then revert `composer.json` before committing:
  ```bash
  /opt/homebrew/opt/php@8.2/bin/php /usr/local/bin/composer require --dev --no-update "playwright-php/playwright-symfony:^0.11"
  /opt/homebrew/opt/php@8.2/bin/php /usr/local/bin/composer update
  /opt/homebrew/opt/php@8.2/bin/php vendor/bin/playwright-install chromium
  (cd tests/Application && yarn install && yarn build && bin/console assets:install public)   # Node 20; copies, not --symlink
  (cd tests/Application && APP_ENV=test bin/console doctrine:database:create --if-not-exists)  # DAMA needs it to exist
  /opt/homebrew/opt/php@8.2/bin/php vendor/bin/phpunit --testsuite Playwright
  git checkout composer.json && /opt/homebrew/opt/php@8.1/bin/php /usr/local/bin/composer update   # back to 8.1
  ```

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

PHPStan runs at `level: max` with strict rules against `src/` and `tests/` (config in `phpstan.neon`), excluding `tests/Application/` and `src/Controller/DebugWebhookController.php`. It boots the test-app kernel (`tests/PHPStan/console_application.php` + `object_manager.php`) for Symfony- and Doctrine-aware analysis, so the kernel must boot for `composer analyse` to run. The console application loader requires the test app's `config/bootstrap.php`, which loads `tests/Application/.env`: the Symfony extension builds a command to read the types of its options and arguments, and the commands' services need the env vars. It holds only the plugin's commands (`setono:sylius-shipmondo:*`), because the extension builds every command it is given, and with the lowest dependencies the test app's `translation:extract` crashes PHPStan (`symfony/translation` < 6.4.3 calls nikic/php-parser 4's API, while PHPStan bundles php-parser 5). `phpstan-webmozart-assert` is kept in `require-dev` (not in the pack). Dependency hygiene is checked by `shipmonk/composer-dependency-analyser` (config in `composer-dependency-analyser.php`), which excludes `tests/`; the CI dependency-analysis job unsets `require-dev` first so `src/` is analysed against the split Sylius packages consumers actually install.

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

# ... under a given hostname instead of the channels' (or framework.router.default_uri's)
(cd tests/Application && bin/console setono:sylius-shipmondo:register-webhooks --hostname=shop.example.com)
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
- code coverage of the unit and functional suites (the code-coverage job, which starts MySQL), checked by Codecov's patch and project checks
- `vendor/bin/phpunit --testsuite Functional`, in the integration-tests job, which starts MySQL
- `vendor/bin/phpunit --testsuite Playwright`, in the browser-tests job (PHP 8.2 only, installs playwright-symfony itself)

The matrix runs **PHP 8.1, 8.2 and 8.3** on **Symfony 6.4** (the plugin requires `symfony/webhook` + `symfony/remote-event`, so Symfony 5.4 is not supported). Coding-standards runs on PHP 8.1 — write syntax compatible with 8.1, not just the locally linked PHP.

## Architecture

### Core Components

**Data Mapping System**
- Located in `src/DataMapper/`
- Uses composite pattern via `CompositeSalesOrderDataMapper`
- Individual mappers (registered via `SalesOrderDataMapperInterface` tag) handle specific aspects:
  - `SalesOrderDataMapper`: Basic order data and addresses
  - `OrderLinesSalesOrderDataMapper`: Order line items
  - `ShippingSalesOrderDataMapper`: The shipping line, per shipment: the shipping adjustment plus the shipment's shipping promotion adjustments (`order_shipping_promotion`), which is the amount Sylius taxes, with the VAT included (a neutral tax adjustment) or on top
  - `PaymentDetailsSalesOrderDataMapper`: Payment details: the order total, and what Shipmondo can capture of it, from the order's *gateway payments* (authorized or completed payments whose payment method is mapped to a payment gateway; other states are ignored). When they all go through one payment gateway, it sends that payment gateway and their sum as `authorized_amount`; through several, neither, with a warning; with none (gift cards alone, nothing mapped), neither. `payment_method` names the largest gateway payment's method through the payment gateway sent, else the largest authorized or completed payment's, the earliest on a tie. It logs an error for a stale mapping of the payment gateway it sends (see Stale Mappings) and sends it anyway
  - `ShipmentTemplateSalesOrderDataMapper`: The shipment template, among the shipping method's allowed ones that ship from the store's country: the country they all ship from, or else the channel's shop billing data's country, both decided among the allowed ones that ship to the shipping address's country. It logs a warning per order for stale mappings, and asks Shipmondo nothing when the shipping method allows no shipment template
  - `ServicePointDataMapper`: The chosen pickup point, as the sales order's service point
- New mappers can be added by implementing `SalesOrderDataMapperInterface` and will be auto-registered via the `setono_sylius_shipmondo.sales_order_data_mapper` tag
- `DataMapper\AmountWithVat` is the one rule for an amount and its VAT, formatted as Shipmondo reads them (`fromAmountIncludingVat()` / `fromAmountExcludingVat()`, `amountExcludingVat()`, `vatAmount()`, `amountIncludingVat()`, `vatPercent()`): the order lines, shipping and payment details mappers all use it, and its VAT percent is `0` when there is no amount excluding VAT (e.g. a free item)

**Order Workflow**
- Defined in `src/Workflow/OrderWorkflow.php`
- States: pending → uploading_to_shipmondo → uploaded_to_shipmondo (or unlinked, or failed), and cancelled. Unlinked: Shipmondo may have a sales order for the order, but which is unknown: the call that creates it ended in an unread success, or failed temporarily (ADR 0002, ADR 0003). Only a sales order lookup moves an order out of it (see Order Upload)
- Transitions: `start_upload`, `complete_upload`, `complete_upload_unlinked` (uploading_to_shipmondo → unlinked), `link` (unlinked → uploaded_to_shipmondo, when a sales order lookup finds one sales order), `fail`, `retry` (uploading_to_shipmondo, failed or unlinked → pending: after a failure that says nothing about the order, when an admin retries a failed order, and when a lookup finds no sales order), `reset` (uploaded_to_shipmondo → pending, whenever the sales order is deleted), `cancel` (pending or failed → cancelled: only from states without a sales order)
- `Workflow\OrderCancellationGuard` lets `cancel` through only for an order cancelled in Sylius. When a sales order is deleted, callers apply `reset` and then `OrderCancellerInterface::cancel()`, which makes a cancelled order cancelled
- Workflow name: `setono_sylius_shipmondo__order`
- State stored in Order entity's `shipmondoState` property. The state owns the link to the sales order (`shipmondoId`): the marking store passes the transition context to `setShipmondoState()`, which links the order on entering uploaded (`complete_upload` and `link` need `[OrderWorkflow::CONTEXT_SALES_ORDER_ID => $id]`, or they throw) and unlinks it on entering any other state. There is no `setShipmondoId()`; tests put an order in a state with `setShipmondoState($state, $context)`
- `OrderWorkflow::getWorkflow()` builds the same state machine without a container, guard included, for unit tests

**Order Upload**
- `Uploader\OrderUploader` owns the whole upload. `upload()` is the upload run: first the sales order lookups (below), then it claims each qualifying order (row lock, re-read, `start_upload`), dispatches `UploadOrder`, and applies the stop rules to the `UploadOutcome` it reads from the `HandledStamp` (none when routed async). `uploadOrder()` maps, creates, links, turns any failure into an `UploadOutcome` (by its Shipmondo failure kind, see below; a 404 counts as rejected, an unread success makes the order unlinked and counts as an upload for the stop rules), settles the Shipmondo state and logs. A temporary failure of the create call makes the order unlinked (Shipmondo may have created the sales order, and has no idempotency key), one while the data mappers read from Shipmondo puts it back to pending.
- Sales order lookup (ADR 0003): `upload()` first looks up each order `Provider\UnlinkedOrdersProviderInterface` finds, unlinked for at least `UnlinkedOrdersProvider::LOOKUP_DELAY` (10 minutes, by `updatedAt`, newest first, whatever its Sylius state, not filtered by `PreQualifiedUploadableOrdersQueryBuilderCreatedEvent`), with `salesOrders()->search(new SalesOrderSearch(orderId: …))` (25 per page, no `archived` filter), keeping exact `order_id` matches (`Resource::$raw`). The order id comes from `Provider\OrderIdProvider`, which `SalesOrderDataMapper` uses too. It settles under a row lock with the order re-read, and leaves one whose version changed (or a guard holds back): one match `link`s, none `retry`s (uploaded in the same run if it qualifies), several leave it unlinked with an error naming them. Then, if cancelled in Sylius and moved, `OrderCancellerInterface::cancel()` (locked and re-read; a Messenger failure to send `DeleteOrder` is logged). A credentials failure stops the run, a 429 stops only the lookups (uploads go on, `upload()` still returns `RateLimited` unless the uploads stopped), anything else leaves the order unlinked, logged, and doesn't count toward the rejected uploads. Lookups never go through the command bus.
- Settling re-reads the order; if it was cancelled while it was uploading, settling makes its normal move and then calls `OrderCancellerInterface::cancel()` (locked and re-read again), which deletes a sales order Shipmondo created meanwhile. The outcome (and so the stop rules) is unchanged. `DeleteOrder` is dispatched with `DispatchAfterCurrentBusStamp`, so it runs after the `UploadOrder` transaction commits; `upload()` logs a `DelayedMessageHandlingException` and reads the outcome from its envelope.
- `UploadOrderHandler` only loads the order and calls `uploadOrder()`. A failed upload is settled, never thrown, so Messenger doesn't retry it: the next upload run is the retry.
- `UploadOrdersCommand` fails only when the run stops early (credentials failure, 429, three rejected uploads in a row) or a 429 stops its lookups, so cron monitoring notices; it is silent otherwise.
- `retry()` is an admin's retry of a failed order: it locks and re-reads the order, applies `retry` only if it is still failed (an uploading order is left to its upload), logs either way and returns whether it did. It never calls Shipmondo: the next upload run uploads the order if it still qualifies.

**Retrying a failed upload (admin)**
- `Controller\Admin\RetryUploadController`: `retry()` (`PUT /admin/orders/{id}/retry-upload`, CSRF token id: the order's id, as Sylius's own transitions) redirects back to the order page with a success or error flash; `bulkRetry()` (`PUT /admin/orders/bulk/retry-upload`, token id `BULK_CSRF_TOKEN_ID`, order ids as `ids[]`, as Sylius's bulk action scripts post them) redirects to the order grid and flashes how many it retried and left (`%count%` pluralized). Both answer 403 on a bad token; the routes take only PUT (a POST with `_method=PUT`). The bulk path is `/orders/bulk/…` because a GET on `/orders/<word>` would match Sylius's order show route and answer 404, not 405
- The order page's sidebar (`sylius.admin.order.show.sidebar`, block `setono_sylius_shipmondo`, priority 32, prepended by the extension) renders `admin/order/show/_shipmondo.html.twig`: the Shipmondo state with the grid column's label template (attached), the sales order id when linked, and the Retry upload form for a failed order
- `EventSubscriber\Grid\AddShipmondoToOrderGridSubscriber` adds the Shipmondo state column, a `select` filter (`shipmondo_state`, every state from `OrderWorkflow::getStates()`) and the bulk action (name and type `setono_sylius_shipmondo_retry_upload`, whose template the extension prepends to `sylius_grid.templates.bulk_action`), creating the grid's `bulk` action group, which Sylius's order grid lacks; that adds the grid's checkbox column

**Shipmondo Failures**
- `Failure\ShipmondoFailure::of(\Throwable)` sorts a failed call to Shipmondo into a `Failure\ShipmondoFailureKind` (the glossary's seven kinds) by the SDK exception: 401/403 credentials failure, 429 rate limited, 5xx or no answer (the SDK's `ConnectionException`, e.g. a timeout) temporary failure, 404 not found, any other 4xx rejected, anything else unexpected failure. A success to a call other than a GET that was cut off (a `ConnectionException` with `getResponse()`: Shipmondo answered 2xx but the rest of its answer didn't arrive, e.g. in time) or can't be read (`MalformedResponseException`, including `MappingException`, told apart by its `getRequest()`, SDK 2.0.0-rc.2+) is an unread success, since Shipmondo did what was asked and trying again, e.g. an upload, could do it twice; for a GET a cut-off success stays a temporary failure and an unreadable one an unexpected failure. It carries the status code and Shipmondo's message (`error`), gives every log the same context (`logContext()`), and explains itself to an admin (`TranslatableInterface`, keys `setono_sylius_shipmondo.shipmondo_failure.<kind>`)
- Every caller decides what a kind means for it, and the SDK classes are the only sorting: the upload (settles the order), the sales order lookup (see Order Upload), `DeleteOrderHandler` (not found and an unread success mean gone; it throws the rest), the admin Shipmondo page and webhook registration (flash why; the command prints it and fails), the shop's pickup point endpoint (logs it and answers HTTP 502, without saying why). The admin page, registration and the endpoint catch only `ShipmondoException` (registration also `UnreachableWebhookEndpointException`, see Webhook Handling), so a bug stays loud; the upload and the lookup catch every `\Throwable`, since an upload must settle and one broken order mustn't stop every run, and the deletion sorts every `\Throwable` and throws it on, for `AbsorbDeleteOrderFailureMiddleware` to absorb where failing isn't safe

**Message Bus Architecture**
- Commands: `UploadOrder`, `DeleteOrder` in `src/Message/Command/`
- Handlers: Located in `src/Message/CommandHandler/`
- Uses dedicated command bus: `setono_sylius_shipmondo.command_bus`
- Bus middleware: doctrine_transaction, `AbsorbDeleteOrderFailureMiddleware` (see Order Cancellation)
- Webhook registration is not on the bus (see Webhook Handling): an app's Messenger routing would make it async, and its callers need the answer

**Webhook Handling**
- Symfony webhook component integration
- Webhook routing identifier: `shipmondo`
- Custom parser: `Webhook\WebhookParser`, a thin adapter over the SDK's `WebhookParser` (verifies the HS256 signature, reads the `SMD-*` headers, unwraps the `data` envelope) that yields a `Webhook\RemoteEvent`
- Webhook handlers implement `OrderWebhookHandlerInterface` (auto-tagged `setono_sylius_shipmondo.order_webhook_handler`; the tag's priority orders the handlers of one action): the static `getSubscribedActions()` names the actions on the orders resource a handler handles, and `handle(OrderInterface $order, RemoteEvent $webhook)` reacts. `DependencyInjection\Compiler\RegisterOrderWebhookHandlersPass` reads those actions once, when the container is built, into `Webhook\Handler\OrderWebhookHandlers` (the handlers of each action, by tag priority, created only when needed), which the consumer and the registrar both read. The plugin's are `SalesOrderShippedHandler`, `SalesOrderPaymentHandler` and `SalesOrderDeletedHandler` in `src/Webhook/Handler/`; they neither find the order nor flush
- `WebhookConsumer` routes: it ignores a webhook no handler subscribes to (and every other resource), finds the order once with `OrderResolver` (by its link, `shipmondoId`, never by order number, which another shop on the same Shipmondo account can share; it logs at info when no order is linked), runs `OrderWebhookHandlers::forAction()` and flushes once. It catches nothing: handled synchronously (the default), a failure answers with an error and Shipmondo sends the webhook again; routed async, Messenger retries it
- `WebhookRegistrar` registers one webhook per action in `OrderWebhookHandlers::subscribedActions()`, so webhook registration (`setono:sylius-shipmondo:register-webhooks`) has to run again whenever that set changes
- `WebhookRegistrar::register()` registers and then records the registration (`RegisteredWebhooks`, found by `getVersion()`, which the admin page shows), only once Shipmondo has registered every webhook. The admin's button and `RegisterWebhooksCommand` call it directly, not through the command bus, so they always learn how it went, and catch `ShipmondoException` and `UnreachableWebhookEndpointException` as they are
- `WebhookRegistrar::register()` registers nothing at an endpoint whose host Shipmondo can't reach (`Registrar\WebhookHost::isReachable()`: not `localhost`, a name under `localhost`, `127.0.0.0/8` or anything that isn't a hostname). It throws `Registrar\UnreachableWebhookEndpointException` before it asks Shipmondo anything, so the webhooks registered before are kept, whoever registers
- The endpoint is generated from the router's context: the admin's button registers at the request's host, and flashes why when the registrar refuses it (e.g. the admin opened at localhost). On the command line there is no request, and without `framework.router.default_uri` the router generates `http://localhost`, so `RegisterWebhooksCommand` picks a hostname Shipmondo can reach: `--hostname`, else the enabled channels' hostname (it asks which when there are several, and fails when it can't ask), both registered at `https://<hostname>` with a fresh router context (no port or path from `default_uri`), else the router's own context, from `default_uri`. It skips a hostname Shipmondo can't reach, by the same rule. When none is left it throws `Command\NoWebhookHostnameException` internally, prints its message and fails without registering. It swaps the router's context only while it calls the registrar
- Each webhook's name is the name prefix, the shop's id (8 hex characters of an HMAC with the webhooks key) and the resource and action, e.g. `Setono Sylius Shipmondo Plugin [3fd02dbf] - [orders:delete]`. Registering first deletes only this shop's webhooks: those with the name prefix that carry its id or are sent to its endpoint, so another shop on the same Shipmondo account keeps its own

**Order Cancellation**
- `OrderCancellationListener` calls `Canceller\OrderCanceller` when a Sylius order is cancelled, hooked on both state-machine backends (a winzou callback prepended by the extension, and a Symfony Workflow listener), and flashes whether the sales order was deleted
- `OrderCanceller` makes a pending or failed order cancelled right away, dispatches `DeleteOrder` for an uploaded one, leaves an uploading one to its upload, and leaves an unlinked one unlinked (`Cancellation::LeftUnlinked`, logged at info, and the listener flashes info: the next sales order lookup deletes its sales order, or cancels the order when it finds none); it returns a `Canceller\Cancellation` saying which happened
- `DeleteOrderHandler` applies `reset` once the sales order is gone, then calls the canceller; a failed deletion leaves the order uploaded and linked. It throws its failures (not found and an unread success count as gone): a temporary failure or a rate limit as it is, so a worker retries it, anything else as `UnrecoverableMessageHandlingException`
- `Message\Middleware\AbsorbDeleteOrderFailureMiddleware`, on the command bus right inside `doctrine_transaction`, absorbs a failed `DeleteOrder` (logs it and stamps a `HandledStamp(false)`, which the canceller reads as not deleted) unless the command was received from a transport and no transaction is open around the bus's own: handled synchronously the deletion runs inside Sylius's cancel transaction, and a failure through `doctrine_transaction` would mark it rollback-only and make the cancel fail

**Pickup Points**
- The chosen pickup point is the SDK's `PickupPoint`. `GetPickupPointsAction` (the shop's `/ajax/get-pickup-points`, HTTP 400 unless the shipping method has pickup point delivery, HTTP 502 when Shipmondo fails; it catches `ShipmondoException` before `\InvalidArgumentException`, which the SDK's `InvalidUrlException` also is) searches Shipmondo, and the template puts each pickup point's JSON in a radio button, which `pickup-point-manager.js` names after the shipping method field
- `shop/_javascripts.html.twig` loads the picker only when an enabled shipping method has pickup point delivery: `pickup-point-manager.js` reads an empty `allowedShippingMethods` as all shipping methods
- When the pickup points can't be loaded, `pickup-point-manager.js` stops waiting, inserts its `loadFailedHtml` (rendered from `shop/pickup_points_not_loaded.html.twig` in `shop/_javascripts.html.twig`) and dispatches `pickup_point_manager:load_failed`; its Try again button (an element with `data-pickup-point-manager-load-again` inside the message's `.pickup-points-container`), or choosing the shipping method again, loads them again. The button is the only way when the failed shipping method is the selected one, since a selected radio fires no `change`
- `Form\Extension\ShipmentTypeExtension` adds an unmapped hidden `shipmondoPickupPoint` field and, on `POST_SUBMIT` once the shipping method is known (and Sylius could read it), parses it with `Parser\PickupPointParser` (JSON, then `PickupPoint::fromArray()`): a shipping method without pickup point delivery gets no pickup point, and one with it needs a readable one (`ERROR_MISSING_PICKUP_POINT`, `ERROR_INVALID_PICKUP_POINT`). Its errors go on the root form, the only errors Sylius's shipping step renders
- `Doctrine\Type\PickupPointType` (registered by the extension's prepend) stores it in the shipment's JSON column and reads it with the same parser; a stored value it can't read reads as `null`. `ServicePointDataMapper` sends it with `ServicePoint::fromPickupPoint()`
- Turning off pickup point delivery in the admin clears the carrier code: a `SUBMIT` listener on the `carrierCode` field of `ShippingMethodTypeExtension`

**Stale Mappings**
- A stale mapping is a payment method mapped to a payment gateway, or a shipping method that allows a shipment template, that no longer exists in Shipmondo. It is kept until an admin changes it (report only): the admin Shipmondo page flags it (a warning message per table, the row marked `warning`, a selected "Not in Shipmondo (id …)" option) and shows the table's form whenever there is one to remove, also when Shipmondo has none left, and the upload logs it and uploads as before. Shipmondo accepts a sales order with a payment gateway it doesn't have (checked in the sandbox), hence the error level for that one
- `Provider\SetupProviderInterface` (`SetupProvider`) owns the rule (`stalePaymentGateway()`, `staleShipmentTemplates()`) and the lists both mappers and the admin page read, each fetched once until it is reset (`kernel.reset`): once per upload run on the command line, once per order when a worker handles `UploadOrder` (Messenger resets services after each message)
- `ShipmondoController` fetches each list on its own and tells its template which were fetched (`paymentGatewaysFetched`, `shipmentTemplatesFetched`): a table whose list couldn't be fetched says it couldn't be loaded and shows no form, and the flash names what failed (one flash for both when they failed for the same reason). It passes the stale mappings to its template as `\SplObjectStorage`s keyed by the Sylius method, each only once its list was fetched: a failed fetch flags nothing in its table, and the other list is still flagged, or saving its form would remove its stale mappings

### Entity Extensions

The plugin extends these Sylius entities via traits:
- **Order**: `OrderTrait` - adds the Shipmondo state, which owns the link to the sales order (`shipmondoId`)
- **Shipment**: `ShipmentTrait` - adds the chosen pickup point (`shipmondoPickupPoint`, the SDK's `PickupPoint`, see Pickup Points above)
- **ShippingMethod**: `ShippingMethodTrait` - adds pickup point delivery flag and carrier code
- **PaymentMethod**: `PaymentMethodTrait` - adds Shipmondo payment name

### Key Services

- `OrderUploaderInterface`: the upload run (sales order lookups, then uploads), the upload of one order and an admin's retry of a failed order (see Order Upload above)
- `SalesOrderDataMapperInterface`: Maps Sylius orders to Shipmondo API format
- Registrars in `src/Registrar/`: Handle webhook registration

### Configuration

- Plugin must be registered **before** `SyliusGridBundle` in `bundles.php`
- Environment variables required: `SHIPMONDO_USERNAME`, `SHIPMONDO_KEY`, `SHIPMONDO_WEBHOOKS_KEY`, `SHIPMONDO_SANDBOX` (bool; selects the production vs. sandbox API — the `api.sandbox` option defaults to `%env(bool:SHIPMONDO_SANDBOX)%`)
- HTTP clients: the extension prepends two framework scoped HTTP clients, scoped to `https://(app|sandbox)\.shipmondo\.com/` with `retry_failed: false` (Symfony's retry repeats even a POST, which could create a sales order twice): `setono_sylius_shipmondo.http_client` (`timeout` 10, `max_duration` 30) and `setono_sylius_shipmondo.pickup_points_http_client` (5 and 10, for the shop's pickup point search, which a customer waits for in checkout). The one `Setono\Shipmondo\Client\Client` service sends through `HttpClient\ShipmondoHttpClient`, a PSR-18 client that sends a request whose path ends in `/pickup_points` through the PSR-18 version (`psr18.<name>`) of the pickup points client and every other request through the other's, since the SDK's `Client` is immutable and takes one PSR-18 client. Callers, and a shop's decorator of the `Client` service, all get the one `Client` service. A shop overrides a limit in its own framework config (`docs/adr/0001-request-time-limits-through-framework-scoped-http-clients.md`, UPGRADE.md section 22). So the plugin requires `symfony/http-client`, which `composer-dependency-analyser.php` marks as used, since only config uses it
- Routes: Use `routes.yaml` for locale-aware apps, `routes_no_locale.yaml` otherwise
- Service ids: every service in `src/Resources/config/services/*.xml` has its class's FQCN as its id, with no `class` attribute (the SDK's `Client` and `WebhookParser` too). Anonymous (inline) services are exempt. The interfaces of eight services are private aliases to them (`OrderUploaderInterface`, `OrderCancellerInterface`, `SalesOrderDataMapperInterface` to the composite, `PreQualifiedUploadableOrdersProviderInterface`, `UnlinkedOrdersProviderInterface`, `SetupProviderInterface`, `WebhookRegistrarInterface`, `OrderResolverInterface`), and consumers get the service through that alias, so decorating the interface reaches them. The SDK's interfaces get no alias. `SetonoSyliusShipmondoExtensionTest` enforces the naming. The prefix stays only on parameters, tags, `setono_sylius_shipmondo.command_bus` and the services Sylius defines for the `registered_webhooks` resource
- Config location: `src/Resources/config/`

### Frontend Integration

- Admin UI: JavaScript enhancements for shipping method forms; the order page's Shipmondo section and the order grid's Shipmondo state column, filter and retry bulk action (see Retrying a failed upload)
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

Unit tests live in `tests/Unit/`, functional tests in `tests/Functional/` and browser tests in `tests/Playwright/`, with a full Sylius test application in `tests/Application/`.
The test app includes custom entity implementations in `tests/Application/Model/`.

## Agent skills

- When grilling (`/mattpocock-skills:grilling`, `/mattpocock-skills:grill-me`, `/mattpocock-skills:grill-with-docs`, and grilling inside other skills), ask via the `AskUserQuestion` tool instead of as plain chat text. The skills ask in chat on purpose and leave this to the project's CLAUDE.md ([mattpocock/skills: native question tool](https://github.com/mattpocock/skills/blob/main/.out-of-scope/native-question-tool.md)).

### Issue tracker

Issues and specs live in GitHub Issues on `Setono/SyliusShipmondoPlugin`, managed with the `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

The five default triage labels are used as-is (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: one `GLOSSARY.md` and `docs/adr/` at the repo root, created only when a term or decision gets settled. See `docs/agents/domain.md`.
