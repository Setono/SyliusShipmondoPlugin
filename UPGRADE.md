# Upgrade guide

## From 1.x to 2.x

2.x migrates the plugin from **`setono/shipmondo-php-sdk` v1 to v2**. The SDK reorganised its
request/response classes (singularised namespaces, enums instead of class constants) and the
`Client` gained a `sandbox` flag. It also makes the plugin react to incoming webhooks and delete the
Shipmondo sales order when a Sylius order is cancelled.

For most installations the required changes are:

- define the new `SHIPMONDO_SANDBOX` environment variable (section 2)
- make `SHIPMONDO_WEBHOOKS_KEY` at least 32 bytes long and re-run
  `setono:sylius-shipmondo:register-webhooks`, which now registers only the webhooks the plugin reacts to (section 4)
- optionally drop the no longer used `setono_sylius_shipmondo__remote_event` table (section 4)
- check the orders that 1.x left stuck in `uploading_to_shipmondo` against Shipmondo, then link or reset them
  (section 6)
- if you monitor the `setono:sylius-shipmondo:upload-orders` cron job, note that it now fails only when the upload
  run stops early or reports another problem that most likely hits every order (sections 6 and 28)
- move the cancelled orders that 1.x left pending or failed to the new `cancelled` Shipmondo state (section 7)
- if you override the order grid's Shipmondo state label templates, rename `dispatching.html.twig` and
  `dispatched.html.twig` (section 7)
- run a database migration, which adds a comment to the shipment's pickup point column (section 8), a column for
  when the order's Shipmondo state last changed (section 25), one for the Shipmondo failure that put the order in
  its Shipmondo state (section 26), and replaces the registered webhooks' version with their endpoint (section 30)
- note that a shipping method with pickup point delivery now needs a pickup point in checkout, and don't let a
  channel skip the shipping step when its only shipping method has pickup point delivery (section 8)
- if you monitor `setono:sylius-shipmondo:register-webhooks`, note that it now fails when Shipmondo does (section 9)
- if you override the admin Shipmondo page's template, add the hidden list of shipping methods (section 10)
- note that an order with a free item now uploads (section 11)
- if a shipping method allows shipment templates to one country from several countries, set the channels' shop billing
  data country (section 12)
- if you reference, decorate or replace one of the plugin's services by its id, use its new id: every service is now
  named after its class (section 13)
- watch the log for the error about a payment method mapped to a payment gateway that no longer exists in Shipmondo,
  and if you override the admin Shipmondo page's template, flag such mappings too (section 14)
- if you override the admin Shipmondo page's template, say when a list couldn't be loaded (section 15)
- note that the shipping line of a sales order now counts the shipping promotions (section 16)
- after registering the webhooks, delete in Shipmondo the old ones it leaves, sent to another address than the one it
  registers now, and if several shops share a Shipmondo account, upgrade them all and give each its own
  `SHIPMONDO_WEBHOOKS_KEY` (section 17)
- note that `setono:sylius-shipmondo:register-webhooks` now registers the webhooks at the channels' hostname, and
  pass it `--hostname` where it can't ask which one, e.g. in a deploy script, when your channels have several
  (section 18)
- note that registering the webhooks now refuses an endpoint Shipmondo can't reach, e.g. from the admin opened at
  localhost, and if you register them from your own code, expect `UnreachableWebhookEndpointException` (section 19)
- if you dispatch the `RegisterWebhooks` command, call `WebhookRegistrarInterface::register()` instead, and if your
  Messenger routing names it, remove that route (section 20)
- note that a sales order's authorized amount is now what its gateway payments hold, and is left out when the sales
  order carries no payment gateway, and remove any code of yours that deducts gift card or other payments from it
  (section 21)
- note that the requests to Shipmondo now have time limits, through two framework scoped HTTP clients the plugin
  prepends and you can override, that the plugin now requires `symfony/http-client` with `framework.http_client`
  enabled, that your `framework.http_client.default_options` now apply to them, and that when one file of yours configures
  `default_options.retry_failed` and overrides one of those clients, you repeat `retry_failed: false` there (section 22)
- note the new `unlinked` Shipmondo state, for an order Shipmondo may have a sales order for although its upload
  failed: Shipmondo's success couldn't be read (section 23), or the call that creates the sales order failed
  temporarily (section 25). The upload run looks its sales order up ten minutes later, and links the order to it,
  uploads it again, or deletes the sales order of an order cancelled meanwhile; and if you monitor
  `setono:sylius-shipmondo:upload-orders`, note that it now also fails when Shipmondo rate limits those lookups
  (section 25)
- note that an admin can now retry the upload of a failed order, from the order page or in bulk from the order grid,
  which gets a Shipmondo state filter, a bulk action and a column of checkboxes, and if you override Sylius's order page
  sidebar, keep the plugin's Shipmondo section (section 24)
- note that the order page's Shipmondo section now shows why the order's latest upload didn't go through, and if your
  order entity doesn't use `OrderTrait`, implement `getShipmondoFailure()` (section 26)
- if several shops share a Shipmondo account, give each its own order id prefix, while no order is unlinked or
  uploading (section 27)
- note that the upload run now also stops after three temporary failures, failed uploads or failed lookups in a row,
  and that `setono:sylius-shipmondo:upload-orders` fails after three unread successes in a row, though the run goes on,
  and if you call `OrderUploaderInterface::upload()`, read the list of problems it now returns (section 28)
- upgrade PHP to 8.2 first: the plugin now requires it, with the Shipmondo SDK's 2.0.0-rc.3; and if you override the
  shop's pickup point list, read `pickupPoint.zipCode` (section 29)
- schedule `setono:sylius-shipmondo:register-webhooks` daily and run it on deploy: registering now fixes only what
  differs in Shipmondo, so that keeps the webhooks matching after an upgrade; and give an install that shares another's
  webhooks key, e.g. a staging copy of production, a key of its own (section 30)

The renamed SDK classes and the other class changes only matter if you extend the plugin.

> The plugin targets **PHP 8.2+** (since section 29; it was 8.1+), **Symfony 6.4** and **Sylius ~1.14**.

### 1. Update the dependency

```bash
composer require setono/sylius-shipmondo-plugin:^2.0
```

This pulls in `setono/shipmondo-php-sdk:^2.0` (2.0.0-rc.2 or later).

### 2. Define the `SHIPMONDO_SANDBOX` environment variable (required)

2.x adds a `sandbox` switch that selects the Shipmondo production API or the sandbox
(`https://sandbox.shipmondo.com`). It defaults to the `SHIPMONDO_SANDBOX` environment variable,
which you must now define next to the existing Shipmondo variables (otherwise the container fails
with *"Environment variable not found: SHIPMONDO_SANDBOX"*):

```dotenv
# .env
SHIPMONDO_USERNAME=
SHIPMONDO_KEY=
SHIPMONDO_WEBHOOKS_KEY=
SHIPMONDO_SANDBOX=false   # set to true to talk to the Shipmondo sandbox
```

If you'd rather configure it explicitly instead of via the env variable:

```yaml
# config/packages/setono_sylius_shipmondo.yaml
setono_sylius_shipmondo:
    api:
        sandbox: false
```

### 3. Only if you extend the plugin: update the SDK class references

If you have custom `SalesOrderDataMapperInterface` implementations, listeners for the plugin's
mapping events, or other code that touches the Shipmondo SDK types exposed by the plugin, update
the class names. The objects are still **mutated in place** exactly as in 1.x — only the types
changed:

| 1.x (SDK v1) | 2.x (SDK v2) |
|---|---|
| `Setono\Shipmondo\Request\SalesOrders\SalesOrder` | `Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest` |
| `Setono\Shipmondo\Request\SalesOrders\Address` | `Setono\Shipmondo\Request\SalesOrder\Recipient` (uses `zipcode`, not `zipCode`) |
| `Setono\Shipmondo\Request\SalesOrders\OrderLine` | `Setono\Shipmondo\Request\SalesOrder\OrderLine` |
| `OrderLine::LINE_TYPE_*` constants | `Setono\Shipmondo\Enum\OrderLineType` enum (e.g. `OrderLineType::Shipping`) |
| `Setono\Shipmondo\Request\SalesOrders\PaymentDetails` | `Setono\Shipmondo\Request\SalesOrder\PaymentDetails` |
| `Setono\Shipmondo\Request\SalesOrders\ServicePoint` | `Setono\Shipmondo\Request\SalesOrder\ServicePoint` |
| `Setono\Shipmondo\Response\ShipmentTemplates\ShipmentTemplate` | `Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate` |
| `Setono\Shipmondo\Response\PaymentGateways\PaymentGateway` | `Setono\Shipmondo\Response\PaymentGateway\PaymentGateway` |
| `Setono\Shipmondo\Response\PickupPoints\PickupPoint` | `Setono\Shipmondo\Response\PickupPoint\PickupPoint` |

The concrete touch points in the plugin's public API:

- **`SalesOrderDataMapperInterface::map()`** — the second argument changed from
  `Request\SalesOrders\SalesOrder` to `Request\SalesOrder\SalesOrderRequest`:

  ```diff
  -use Setono\Shipmondo\Request\SalesOrders\SalesOrder;
  +use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;

  -public function map(OrderInterface $order, SalesOrder $salesOrder): void
  +public function map(OrderInterface $order, SalesOrderRequest $salesOrder): void
  ```

- **`SalesOrderMappedEvent`**, **`OrderLineMappedEvent`** and **`ShippingOrderLineMappedEvent`**
  now expose the v2 request objects (`SalesOrderRequest` / `OrderLine`). Update listener type hints.

- **`ShippingMethodInterface`** / **`ShippingMethodTrait`** reference
  `Response\ShipmentTemplate\ShipmentTemplate` (was `Response\ShipmentTemplates\ShipmentTemplate`).

If you call the Shipmondo SDK client directly (pagination, pickup-point search, webhook
registration, the `Client` constructor, exceptions, …), read the SDK's own upgrade guide for the
full set of changes:
<https://github.com/Setono/shipmondo-php-sdk/blob/2.x/UPGRADE.md>

### 4. Incoming webhooks now transition the order — `RemoteEvent` persistence removed

In 1.x, received webhooks were only stored in the `setono_sylius_shipmondo__remote_event` table and
nothing acted on them. In 2.x the plugin **reacts to webhooks immediately**:

- `orders / create_shipment` (a shipment was created in Shipmondo — the order moves to `order_status`
  "sent" with `shipped_percent` 100) transitions the Sylius shipment(s) to `shipped`, which fulfils the
  order once payment is complete. `orders / status_update` is also honoured for robustness, gated on the
  same `shipped_percent` reaching 100.
- `orders / delete` (a sales order was deleted in Shipmondo) resets the order's Shipmondo upload state
  (back to `pending` via the new `reset` transition, clearing its `shipmondoId`), so the next
  `upload-orders` run re-uploads it. This only happens when the deleted sales order is the one the order
  is linked to (its `shipmondoId`); deleting a duplicate with the same order number is ignored. It is
  deliberately *not* treated as a cancellation — cancelling an order is a Sylius-side decision.
- `orders / payment_captured` completes the Sylius payment(s) → the order's payment state becomes `paid`
  (orders are uploaded while either `paid` or `authorized`, so an authorized payment can be captured
  later in Shipmondo), and `orders / payment_voided` cancels the order's payment state.

The plugin now **registers only the webhooks it reacts to**: the five above. 1.x registered eleven, including the
`shipments` and `shipment_monitor` webhooks and `orders / create` and `create_fulfillment`, which nothing acted on.
Until you re-run `setono:sylius-shipmondo:register-webhooks` (see below), Shipmondo keeps sending the old set, and the
plugin acknowledges the webhooks it doesn't react to and ignores them.

Each reaction is a **webhook handler**, a service implementing
**`Webhook\Handler\OrderWebhookHandlerInterface`**, so you can add your own the same way you add data mappers. It is
autoconfigured with the `setono_sylius_shipmondo.order_webhook_handler` tag, whose priority orders the handlers of
the same action. A handler:

- names the actions on the orders resource it handles in the static `getSubscribedActions()`, a list of the SDK's
  `Setono\Shipmondo\Enum\WebhookAction` enum, read once when the container is built. Webhook registration subscribes
  to exactly those, so re-run `setono:sylius-shipmondo:register-webhooks` after adding a handler for another action;
- gets `handle(OrderInterface $order, Webhook\RemoteEvent $webhook)`: the Sylius order the webhook belongs to, and the
  webhook, whose payload is the sales order (the parser unwraps the webhook's `data` envelope).
  `RemoteEvent::getResource()` / `getAction()` now return the SDK enums `WebhookResourceName` / `WebhookAction`
  instead of strings.

`Webhook\WebhookConsumer` finds the order once, through `Webhook\OrderResolverInterface`: the order linked to the
sales order. It never falls back to the order number, because another shop on the same Shipmondo account numbers its
orders the same way. It runs the handlers subscribed to the action and then flushes once. A webhook about a sales
order no order is linked to, such as another shop's, is logged at `info` level and acknowledged. Nothing is caught: if a handler fails, or the order changed meanwhile, the
webhook fails. Handled synchronously, which is the default, it gets an error response and Shipmondo sends it again;
if you route Symfony's `ConsumeRemoteEventMessage` to an async transport, Messenger retries it instead.

Webhook verification and parsing are delegated to the SDK's `Setono\Shipmondo\Webhook\WebhookParser`
(the plugin requires `setono/shipmondo-php-sdk:^2.0` and `firebase/php-jwt:^7.0`). Shipmondo identifies
each delivery with `SMD-*` request headers, so the registered endpoint no longer carries
`resource`/`action` query parameters — **re-run `setono:sylius-shipmondo:register-webhooks`** after
upgrading (section 18 says at which hostname). The SDK signs and verifies deliveries with HS256, which requires a key of at least 32 bytes,
so **`SHIPMONDO_WEBHOOKS_KEY` must now be at least 32 bytes long** (a shorter key is rejected both when
registering and when verifying).

Because of this, the **write-only `RemoteEvent` Sylius resource/entity was removed**: the
`setono_sylius_shipmondo__remote_event` table is no longer used and **can be dropped** (generate a
migration in your app).

Class, service and configuration changes, which only matter if you extend or reference them:

| 1.x | 2.x |
|---|---|
| `Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent` (`getResource()` / `getAction()` return strings) | `Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent` (they return the `WebhookResourceName` / `WebhookAction` enums) |
| `Setono\SyliusShipmondoPlugin\Model\RemoteEvent` / `RemoteEventInterface` | removed |
| `Setono\SyliusShipmondoPlugin\Factory\RemoteEventFactory` / `RemoteEventFactoryInterface` | removed |
| the `setono_sylius_shipmondo.resources.remote_event` config node and its `setono_sylius_shipmondo.model.remote_event.*` parameters | removed |
| `Setono\SyliusShipmondoPlugin\RequestMatcher\HasQueryParameterRequestMatcher` | removed (webhooks are matched on their `SMD-*` headers) |
| `Webhook\WebhookParser::__construct(string $webhooksKey)` | `__construct(Setono\Shipmondo\Webhook\WebhookParserInterface $shipmondoWebhookParser, string $webhooksKey)` |
| `Webhook\WebhookConsumer::__construct(RemoteEventFactoryInterface, ManagerRegistry)` | `__construct(Webhook\OrderResolverInterface $orderResolver, Webhook\Handler\OrderWebhookHandlers $handlers, Psr\Log\LoggerInterface $logger, ManagerRegistry $managerRegistry)` |
| `Registrar\WebhookRegistrar::__construct($client, $urlGenerator, string $webhooksKey, string $namePrefix)` | `__construct($client, $urlGenerator, Webhook\Handler\OrderWebhookHandlers $handlers, Repository\RegisteredWebhooksRepositoryInterface $registeredWebhooksRepository, Sylius\Component\Resource\Factory\FactoryInterface $registeredWebhooksFactory, string $webhooksKey, string $namePrefix)` (the repository and the factory are section 20's); `getWebhooks()` yields one webhook per action on the orders resource that a handler subscribes to |
| `Registrar\Webhook::$action` / `$resource` are strings | they are the SDK's `WebhookAction` / `WebhookResourceName` enums |

New:

- `Webhook\Handler\OrderWebhookHandlerInterface` (see above);
- the plugin's three webhook handlers `Webhook\Handler\SalesOrderShippedHandler`, `SalesOrderPaymentHandler` and
  `SalesOrderDeletedHandler`;
- `Webhook\Handler\OrderWebhookHandlers`, the handlers of each action, which
  `DependencyInjection\Compiler\RegisterOrderWebhookHandlersPass` fills when the container is built;
- `Webhook\OrderResolverInterface` and `Webhook\OrderResolver`, which find the Sylius order linked to a webhook's sales
  order;
- the `reset` transition (`OrderWorkflow::TRANSITION_RESET`, `uploaded_to_shipmondo` → `pending`) on the
  `setono_sylius_shipmondo__order` workflow;
- the `Message\Command\DeleteOrder` command (section 5).

### 5. Cancelling a Sylius order deletes its Shipmondo sales order

When an order is cancelled in Sylius, the plugin deletes the corresponding sales order in Shipmondo (if it
was uploaded) and flashes a message in the admin so the user knows it happened. The cancellation is caught
on **both** state-machine backends — a winzou callback on the `sylius_order` `cancel` transition (Sylius
1.14's default) and a Symfony Workflow listener on `workflow.sylius_order.completed.cancel` (for apps whose
`sylius_order` graph uses the Workflow adapter) — so it keeps working as Sylius migrates from winzou to
Symfony Workflow.

The deletion runs through a new `Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder` command on the
`setono_sylius_shipmondo.command_bus`, handled synchronously by default; route it to an async transport if
you don't want the Shipmondo API call inside the cancellation request. Once the sales order is gone, the order's
Shipmondo state becomes `cancelled` (section 7). (Conversely, deleting a sales order *in Shipmondo* puts an order
that isn't cancelled back to `pending` so it is re-uploaded — see above — it is not treated as a cancellation.)

A failing Shipmondo call never blocks the cancellation. A sales order that is already gone in Shipmondo (404) counts as
deleted, and so does a deletion Shipmondo answered with a success that was cut off or couldn't be read (section 23). On any other failure the order stays `uploaded_to_shipmondo` and keeps its `shipmondoId`, and:

- **handled synchronously**, the failure is logged and the admin sees an error telling them to delete the sales order
  in Shipmondo manually;
- **routed to an async transport**, the worker retries a temporary failure (a Shipmondo server error or a network
  problem) or a rate limit with the transport's own retry strategy, and sends any other failure (e.g. Shipmondo doesn't
  accept the credentials) straight to the failure transport, where a deletion that runs out of retries ends too. Watch
  it with `bin/console messenger:failed:show` ([#16](https://github.com/Setono/SyliusShipmondoPlugin/issues/16)).

`DeleteOrderHandler` throws its failures, and `Message\Middleware\AbsorbDeleteOrderFailureMiddleware`, which the plugin
puts on its command bus right inside `doctrine_transaction`, absorbs them where failing isn't safe: when the command
wasn't received from a transport, or a transaction is open around the bus's own. So a failure is only logged, not
retried, when `DeleteOrder` is routed to `sync://` (the first pass's transaction is still open) and when a worker wraps
the handling of messages in a transaction of its own.

Only if you extend or call the deletion:

| 1.x | 2.x |
|---|---|
| | `Message\CommandHandler\DeleteOrderHandler::__construct($orderRepository, $shipmondoClient, $orderWorkflow, Canceller\OrderCancellerInterface $orderCanceller)`; `__invoke()` returns `true` once the sales order is gone and throws on any other failure: as it is when trying again may help, as an `UnrecoverableMessageHandlingException` otherwise |
| | new `Message\Middleware\AbsorbDeleteOrderFailureMiddleware` on the `setono_sylius_shipmondo.command_bus` bus. If you configure that bus's middleware yourself, keep it right after `doctrine_transaction` |

### 6. A failed upload settles the order instead of crashing the upload run

In 1.x, any exception during an upload (a Shipmondo error, a network problem, a data mapper that throws) escaped
`setono:sylius-shipmondo:upload-orders`: the upload run aborted, and the order stayed in `uploading_to_shipmondo`
for good. In 2.x, `Uploader\OrderUploader` settles every failure on the order:

| Failure | Order ends in | Synchronous upload run |
|---|---|---|
| Shipmondo rejects the upload: a 4xx other than 401, 403 and 429 (e.g. 422) | `failed` | continues (section 28) |
| The order can't be turned into a sales order (a data mapper throws), or another unexpected error, such as a non-error status (e.g. an unfollowed redirect) | `failed` | continues (section 28) |
| Shipmondo doesn't accept the API credentials: 401 or 403 | `pending` | stops |
| Shipmondo rate limits the upload: 429 | `pending` | stops |
| A temporary failure of a read before the sales order is created, e.g. of the payment gateways: 5xx, a network error, or Shipmondo not answering in time (section 22) | `pending` | continues (section 28) |
| A temporary failure of the call that creates the sales order: 5xx, a network error, or Shipmondo not answering in time (section 22). Shipmondo may have created the sales order before the failure | `unlinked` (section 25) | continues (section 28) |
| Shipmondo answered the creation of the sales order with a success, but its answer was cut off, e.g. because it didn't arrive in time (section 22), or the SDK couldn't read it: Shipmondo created the sales order | `unlinked` (section 23) | continues (section 28) |
| The order changed while it was uploading, before its `UploadOrder` command was handled | `pending` | continues |

- A synchronous upload run also stops after three uploads in a row that make the order `failed`, with no upload
  Shipmondo accepted between them (an unread success counts as accepted, section 23), because Shipmondo answers 422 for
  shop-wide problems too, such as a mapped payment gateway that was deleted in Shipmondo. It stops after three temporary
  failures in a row too, and the command fails after three unread successes in a row (section 28).
- **When the upload run stops early, the command prints why and exits with a failure code**, and the remaining orders
  stay `pending` for the next run. So it does when the run reports another problem (section 28). Otherwise the command
  stays silent and succeeds, also when single uploads failed:
  each failure is logged with the order number, its kind of Shipmondo failure (section 9) and Shipmondo's status code
  and error message, and a `failed` order stays `failed` until an admin retries its upload (section 24).
- Going back to pending uses the new `retry` transition (`OrderWorkflow::TRANSITION_RETRY`,
  `uploading_to_shipmondo` → `pending`, `failed` → `pending` for an admin's retry, section 24, and `unlinked` →
  `pending` when a sales order lookup finds no sales order, section 25) of the `setono_sylius_shipmondo__order`
  workflow. `reset` is unchanged.
- A temporary failure of the call that creates the sales order doesn't put the order back to pending: Shipmondo may
  have created the sales order before the failure, and it has no idempotency key, so uploading the order again could
  create a duplicate. The order becomes `unlinked`, and the upload run looks its sales order up later (section 25).
- **If you route `UploadOrder` to an async transport:** its handler no longer throws for a failed upload, so Messenger
  no longer retries it; the next upload run is the retry. The upload run can't see how async uploads go, so they never
  stop it early or make the command fail: **watch the logs instead**. Its sales order lookups still run in it (section
  25), so they can stop it and make the command fail (section 28). An async worker can't stop the `UploadOrder`
  commands already sent to the transport, so after a credentials failure each of them goes back to pending.
- An order that something else changed after the upload run loaded it (another run, an admin cancelling it, a payment
  update) is skipped and left as it is, so the next upload run checks again whether it qualifies for upload. In 1.x
  the run aborted with a closed entity manager instead (see
  [#34](https://github.com/Setono/SyliusShipmondoPlugin/issues/34)). Likewise, an order that changes while Shipmondo
  is called is still linked to its new sales order.

Orders that 1.x left stuck in `uploading_to_shipmondo` without a Shipmondo id are never uploaded. Most of them got
stuck because Shipmondo refused the upload, but **some may have a sales order in Shipmondo after all**: the upload can
fail after Shipmondo created it, e.g. when its answer was lost or timed out, or when the order changed during the upload.
Putting such an order back to pending uploads it a second time, and it could be packed and shipped twice. So, once
(use your order entity's class):

1. List the stuck orders:

   ```bash
   bin/console doctrine:query:dql "SELECT o.id, o.number FROM App\Entity\Order\Order o WHERE o.shipmondoState = 'uploading_to_shipmondo' AND o.shipmondoId IS NULL"
   ```

2. Search Shipmondo's sales orders for each order number. Link an order Shipmondo has a sales order for, using that
   sales order's id (`123456`) and the order's number (`000001234`):

   ```bash
   bin/console doctrine:query:dql "UPDATE App\Entity\Order\Order o SET o.shipmondoState = 'uploaded_to_shipmondo', o.shipmondoId = 123456 WHERE o.number = '000001234' AND o.shipmondoState = 'uploading_to_shipmondo' AND o.shipmondoId IS NULL"
   ```

3. Put the rest back to pending, so the next upload run uploads them:

   ```bash
   bin/console doctrine:query:dql "UPDATE App\Entity\Order\Order o SET o.shipmondoState = 'pending' WHERE o.shipmondoState = 'uploading_to_shipmondo' AND o.shipmondoId IS NULL"
   ```

Only if you extend or call the upload code:

| 1.x | 2.x |
|---|---|
| `Uploader\OrderUploaderInterface::upload(): void` | `upload(): array`: the `list<Uploader\UploadRunProblem>` the run reports, e.g. what stopped it early, or an empty list (section 28) |
| | new `OrderUploaderInterface::uploadOrder(OrderInterface $order, ?int $version = null): UploadOutcome`, which uploads one order in `uploading_to_shipmondo` and settles its state |
| `Uploader\OrderUploader::__construct($provider, $commandBus, $orderWorkflow, $managerRegistry)` | `__construct($provider, Provider\UnlinkedOrdersProviderInterface $unlinkedOrdersProvider, Provider\OrderIdProviderInterface $orderIdProvider, $commandBus, $orderWorkflow, Setono\Shipmondo\Client\ClientInterface $shipmondoClient, SalesOrderDataMapperInterface $salesOrderDataMapper, Canceller\OrderCancellerInterface $orderCanceller, Psr\Log\LoggerInterface $logger, $managerRegistry)` |
| `Message\CommandHandler\UploadOrderHandler::__construct($orderRepository, $shipmondoClient, $salesOrderDataMapper, $orderWorkflow)`; `__invoke()` returns `void` | `__construct($orderRepository, OrderUploaderInterface $orderUploader)`; `__invoke()` returns the `UploadOutcome` |
| `Workflow\OrderWorkflow::getWorkflow()` returns a `Workflow`, on which `fail` is never enabled | returns a `StateMachine` with the transitions the container registers (see [#38](https://github.com/Setono/SyliusShipmondoPlugin/issues/38)) |

New: the `Uploader\UploadOutcome` enum, which says how an upload went.

### 7. A cancelled order's Shipmondo state becomes cancelled, and only an uploaded order is linked to a sales order

In 1.x, cancelling an order left its Shipmondo state as it was, e.g. `pending` (see
[#5](https://github.com/Setono/SyliusShipmondoPlugin/issues/5)). The `setono_sylius_shipmondo__order` workflow now
has a `cancelled` state (`OrderInterface::SHIPMONDO_STATE_CANCELLED`) and a `cancel` transition
(`OrderWorkflow::TRANSITION_CANCEL`) into it from `pending` and `failed`, the states in which Shipmondo has no sales
order for the order. A guard on the workflow (`Workflow\OrderCancellationGuard`) lets `cancel` through only for an
order that is cancelled in Sylius. When an order is cancelled in Sylius, it depends on its Shipmondo state:

| Shipmondo state | What happens |
|---|---|
| `pending` or `failed` | the order becomes `cancelled` right away |
| `uploaded_to_shipmondo` | its sales order is deleted (section 5): the order is `reset` to `pending` and then becomes `cancelled`. If the deletion fails, the order stays `uploaded_to_shipmondo`; delete the sales order in Shipmondo, and the `orders/delete` webhook makes the order `cancelled` the same way |
| `uploading_to_shipmondo` | it is left to its upload, which makes it `cancelled` once the upload is settled. If Shipmondo created the sales order meanwhile, that sales order is deleted first. If the upload makes the order `unlinked`, it stays so, as below |
| `unlinked` | it stays `unlinked`, since which sales order Shipmondo may have for it is unknown, until the upload run's sales order lookup settles it (section 25): the sales order it finds is deleted, and the order becomes `cancelled`; with none found, the order becomes `cancelled` right away; with several, it stays `unlinked`. The admin is told so |

Whenever an order's sales order is deleted, through `DeleteOrder` or in Shipmondo, the order is `reset` to `pending`,
and then becomes `cancelled` if it is cancelled in Sylius. An order that isn't goes on to be uploaded again, as before.

**If you route `DeleteOrder` to an async transport:** for an order cancelled while it was uploading, the upload sends
`DeleteOrder` once its `UploadOrder` command has finished (Messenger's `DispatchAfterCurrentBusStamp`). If it can't be
sent, the upload run logs it and goes on, and the order stays `uploaded_to_shipmondo`.

The order grid shows a cancelled order with a yellow label, like Sylius shows a cancelled order. Uploading and uploaded
orders now get their coloured labels too (see [#25](https://github.com/Setono/SyliusShipmondoPlugin/issues/25)): the
label templates in `@SetonoSyliusShipmondoPlugin/admin/order/grid/field/shipmondo_state/` were still named after
states that no longer exist. **If you override them, rename your overrides** from `dispatching.html.twig` to
`uploading_to_shipmondo.html.twig` and from `dispatched.html.twig` to `uploaded_to_shipmondo.html.twig`, and add
`cancelled.html.twig` if you want your own.

**The Shipmondo state now owns the link to the sales order:** only an uploaded order is linked to a sales order, and
every move of the Shipmondo state keeps it that way. `setShipmondoId()` is gone. The workflow's marking store passes the
transition's context to `setShipmondoState()`: entering `uploaded_to_shipmondo` links the order to the sales order id
in the context, and entering any other state unlinks it. So apply transitions through the workflow:

```php
$orderWorkflow->apply($order, OrderWorkflow::TRANSITION_COMPLETE_UPLOAD, [
    OrderWorkflow::CONTEXT_SALES_ORDER_ID => $salesOrderId,
]);
```

Once you have upgraded, and after the steps in section 6 (which may put cancelled orders back to `pending`), move the
cancelled orders that 1.x left `pending` or `failed` to `cancelled` (use your order entity's class):

```bash
bin/console doctrine:query:dql "UPDATE App\Entity\Order\Order o SET o.shipmondoState = 'cancelled' WHERE o.state = 'cancelled' AND o.shipmondoState IN ('pending', 'failed')"
```

Cancelled orders that 1.x uploaded stay `uploaded_to_shipmondo`, because 1.x never deleted their sales orders and they
still exist in Shipmondo. Delete a sales order in Shipmondo when you no longer need it, and the `orders/delete` webhook
cancels its order.

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| `Model\OrderInterface::setShipmondoId(?int $shipmondoId)` | removed: the Shipmondo state links the order, see above |
| `Model\OrderInterface::setShipmondoState(string $shipmondoState)` | `setShipmondoState(string $shipmondoState, array $context = [])`, which throws an `\InvalidArgumentException` for `uploaded_to_shipmondo` without an integer under `OrderWorkflow::CONTEXT_SALES_ORDER_ID`. Implement the same rule if your order doesn't use `OrderTrait` |
| `Workflow\OrderWorkflow::getWorkflow(Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher)` | `getWorkflow(Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher)`, which adds the `OrderCancellationGuard` to the event dispatcher |
| | `EventListener\OrderCancellationListener::__construct(Canceller\OrderCancellerInterface $orderCanceller, $requestStack)` |
| | `Message\CommandHandler\DeleteOrderHandler::__construct($orderRepository, $shipmondoClient, Symfony\Component\Workflow\WorkflowInterface $orderWorkflow, Canceller\OrderCancellerInterface $orderCanceller)` |

New: `Canceller\OrderCancellerInterface` and `Canceller\OrderCanceller`, which make the Shipmondo state of an order
that is cancelled in Sylius cancelled too, and the `Canceller\Cancellation` enum, which says what happened;
`Workflow\OrderCancellationGuard`; the `OrderInterface::SHIPMONDO_STATE_CANCELLED`, `OrderWorkflow::TRANSITION_CANCEL`
and `OrderWorkflow::CONTEXT_SALES_ORDER_ID` constants.

### 8. The checkout checks the chosen pickup point, and the shipment stores it as the SDK's `PickupPoint`

In 1.x the shipping step stored whatever the pickup point field held: invalid JSON answered HTTP 500
([#20](https://github.com/Setono/SyliusShipmondoPlugin/issues/20)), JSON of the wrong shape only failed when the order
was uploaded ([#37](https://github.com/Setono/SyliusShipmondoPlugin/issues/37)), and a pickup point could end up on a
shipment whose shipping method has no pickup point delivery
([#27](https://github.com/Setono/SyliusShipmondoPlugin/issues/27)). Once the shipping step is submitted:

| The shipment's shipping method | The submitted pickup point | What happens |
|---|---|---|
| has pickup point delivery | the JSON of a pickup point | it is stored on the shipment |
| has pickup point delivery | none, e.g. because the picker couldn't load the pickup points | the step shows "Please choose a pickup point, or another shipping method." |
| has pickup point delivery | anything else, such as invalid JSON or a missing key | the step shows "The chosen pickup point could not be read. Please choose it again." |
| has no pickup point delivery | anything | the shipment gets no pickup point |

The errors are shown at the top of the shipping step, since Sylius renders neither the pickup point field nor the
shipment's errors. Their translation keys are `setono_sylius_shipmondo.checkout.pickup_point.missing` and
`setono_sylius_shipmondo.checkout.pickup_point.invalid` in the `validators` domain. So a shipping method with pickup
point delivery can't leave the checkout without a pickup point: while Shipmondo's pickup point search fails, the
customer has to choose another shipping method.

**Known gap:** a channel that skips the shipping step when only one shipping method is available never shows the
pickup point picker. If that shipping method has pickup point delivery, its orders are uploaded without a service point.
Don't combine the two.

Also fixed:

- the pickup point picker no longer shows pickup points that arrive after the customer selected another shipping
  method (#27)
- the shop's pickup point endpoint answers HTTP 400 for a shipping method without pickup point delivery, instead of
  searching Shipmondo with its old carrier code
  ([#21](https://github.com/Setono/SyliusShipmondoPlugin/issues/21))
- turning off pickup point delivery in the admin clears the shipping method's carrier code (#21)
- the shipping step loads no pickup point picker when no enabled shipping method has pickup point delivery. Its empty
  list of pickup point shipping methods meant "all of them" to the picker, which requested pickup points for every
  shipping method ([#22](https://github.com/Setono/SyliusShipmondoPlugin/issues/22)). Once a shipping method has
  pickup point delivery, a picker you configure yourself through `window.pickupPointManagerConfig` still reads an empty
  `allowedShippingMethods` as all shipping methods. If you override `@SetonoSyliusShipmondoPlugin/shop/_javascripts.html.twig`,
  render the picker only when `sss_shipping_methods_with_pickup_point_delivery()` isn't empty

**Run a database migration.** The shipment's pickup point column is now mapped with the plugin's Doctrine type
`setono_sylius_shipmondo_pickup_point`, which the bundle registers, and Doctrine names that type in the column's
comment. It is still a JSON column, so the migration only changes the comment. On MySQL it is:

```sql
ALTER TABLE sylius_shipment CHANGE shipmondoPickupPoint shipmondoPickupPoint JSON DEFAULT NULL COMMENT '(DC2Type:setono_sylius_shipmondo_pickup_point)';
```

Generate it with `bin/console doctrine:migrations:diff` as usual. The pickup points stored before the upgrade read as
they are: 1.x and 2.x stored the same fields. A stored value the type can't read, which only a tampered checkout
request could have stored, reads as no pickup point. If your shipment entity maps the column itself instead of
through `ShipmentTrait`, map it with the type `setono_sylius_shipmondo_pickup_point`.

**If you replaced `pickup-point-manager.js` or its `insertHtmlCallback`:** the radio buttons must still carry a pickup
point's JSON, as the pickup point endpoint returns it. That JSON no longer includes `raw`, Shipmondo's whole response
for the pickup point.

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| `Model\ShipmentInterface::setShipmondoPickupPoint(?array $shipmondoPickupPoint)` | `setShipmondoPickupPoint(?Setono\Shipmondo\Response\PickupPoint\PickupPoint $shipmondoPickupPoint)` |
| `Model\ShipmentInterface::getShipmondoPickupPoint(): ?array` | `getShipmondoPickupPoint(): ?Setono\Shipmondo\Response\PickupPoint\PickupPoint`. In Twig, `shipment.shipmondoPickupPoint.name` keeps working |
| `Form\Extension\ShipmentTypeExtension::__construct()` | `__construct(Symfony\Contracts\Translation\TranslatorInterface $translator)`. Its `shipmondoPickupPoint` field is no longer mapped: the extension sets the shipment's pickup point once the shipping method is known |

New: `Doctrine\Type\PickupPointType`, `Parser\PickupPointParser`, and the `ShipmentTypeExtension::ERROR_MISSING_PICKUP_POINT` and `ERROR_INVALID_PICKUP_POINT` constants (the translation keys of the errors). The SDK's `PickupPoint::fromArray()` rebuilds a pickup point from its JSON, and
`ServicePoint::fromPickupPoint()` turns it into a sales order's service point; both need `setono/shipmondo-php-sdk`
2.0.0-beta.2 or later.

### 9. A failed call to Shipmondo is explained, instead of ending in HTTP 500

In 1.x each place that calls Shipmondo handled a failure its own way: the admin's Shipmondo page blamed the API
credentials for every failure, registering the webhooks ended on an HTTP 500 page
([#24](https://github.com/Setono/SyliusShipmondoPlugin/issues/24)), and the shop's pickup point endpoint answered
HTTP 500, after which the pickup point picker waited for the pickup points for as long as the page was open
([#23](https://github.com/Setono/SyliusShipmondoPlugin/issues/23)). In 2.x, a failed call to Shipmondo is sorted into
one kind of Shipmondo failure (`Failure\ShipmondoFailureKind`), and each caller acts on the kind:

| Kind | When |
|---|---|
| `credentials_failure` | Shipmondo answers 401 or 403 |
| `rate_limited` | Shipmondo answers 429 |
| `temporary_failure` | Shipmondo answers 5xx, or doesn't answer (a network error, or not in time, see section 22) |
| `not_found` | Shipmondo answers 404 |
| `rejected` | Shipmondo answers any other 4xx |
| `unexpected_failure` | anything else: a redirect, a success to a read that the SDK can't read, or a failure that isn't Shipmondo's answer, such as a data mapper that throws |
| `unread_success` | Shipmondo answers a call that changes something (anything but a GET), such as creating or deleting a sales order, with a success that is cut off, e.g. because it doesn't arrive in time (section 22), or that the SDK can't read (section 23) |

- **The admin's Shipmondo page** says why it couldn't fetch the payment gateways or the shipment templates, e.g. that
  Shipmondo couldn't be reached, instead of always asking you to check the credentials, and logs it as a warning (see
  section 15 for how it tells which list it couldn't fetch). It only catches Shipmondo failures: any other exception
  is a bug, and ends on an error page.
- **Registering the webhooks** with the admin's "Register webhooks" button returns to the Shipmondo page with an error
  flash that says why, and that the webhooks may be only partly registered (registration deletes the shop's webhooks
  before it creates them), so register them again. `setono:sylius-shipmondo:register-webhooks` prints the same and
  exits with a failure code. Both log the failure as an error. The success flash shows only when registration
  succeeded.
- **The shop's pickup point endpoint** answers HTTP 502, and logs the failure: as a warning for a temporary failure or
  a rate limit, as an error otherwise. The answer doesn't say why, so the shop's visitors aren't told. **The pickup
  point picker** then stops waiting, shows "The pickup points could not be loaded. Try again, or choose another shipping
  method." with a "Try again" button where the pickup points go, and dispatches a `pickup_point_manager:load_failed`
  event (its `detail` has the `radio` and the `shippingMethod`, like the other events). The button, or choosing the
  shipping method again, loads its pickup points again. The button also works when the shipping method can't be chosen
  again, e.g. when it is the channel's only one.
- **An upload and a sales order deletion** log the kind next to Shipmondo's status code and message
  (`shipmondoFailure`, `shipmondoStatusCode`, `shipmondoError` in the log context). An upload settles the order as in
  section 6; a 404 counts as a rejected upload.

The picker's message is rendered by `@SetonoSyliusShipmondoPlugin/shop/pickup_points_not_loaded.html.twig`, which you
can override like any bundle template, and is passed to the picker as its new `loadFailedHtml` option (you can also set
it in `window.pickupPointManagerConfig`). Clicking an element with the `data-pickup-point-manager-load-again` attribute
inside its `.pickup-points-container`, such as the template's button, loads the pickup points again, so keep both the
class and the attribute if you override the template. **If you replaced `pickup-point-manager.js` or its
`insertHtmlCallback`:** `getHtml()` returns the `loadFailedHtml` for a shipping method whose pickup points couldn't be
loaded, so a callback that inserts `getHtml()` shows the message and its button without changes.

New translation keys: `setono_sylius_shipmondo.shipmondo_data_not_fetched`, `setono_sylius_shipmondo.webhooks_registered`
and `setono_sylius_shipmondo.webhooks_not_registered` in the `flashes` domain (the last one takes `%reason%`), and
`setono_sylius_shipmondo.shipmondo_failure.<kind>` (it takes `%error%`, Shipmondo's message),
`setono_sylius_shipmondo.ui.pickup_points_not_loaded` and `setono_sylius_shipmondo.ui.try_again` in the `messages`
domain. The English text of the success flash
after registering the webhooks is unchanged.

This needs `setono/shipmondo-php-sdk` 2.0.0-beta.3 or later, whose exceptions tell a 403 (`ForbiddenException`), any
other 4xx (`UnexpectedClientErrorException`) and a call that got no answer (`ConnectionException`, which wraps the HTTP
client's exception) apart. If your own code catches the SDK's exceptions, see the SDK's
[UPGRADE.md](https://github.com/Setono/shipmondo-php-sdk/blob/2.x/UPGRADE.md#exceptions).

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| `Controller\Admin\ShipmondoController::__construct(..., Client $client)` | `__construct(..., Client $client, Symfony\Contracts\Translation\TranslatorInterface $translator, Psr\Log\LoggerInterface $logger)` |
| `Command\RegisterWebhooksCommand::__construct(MessageBusInterface $commandBus)` | `__construct(Registrar\WebhookRegistrarInterface $webhookRegistrar, ChannelRepositoryInterface $channelRepository, UrlGeneratorInterface $urlGenerator, TranslatorInterface $translator, LoggerInterface $logger)` (the channel repository and the URL generator are section 18's, the registrar section 20's); it returns `Command::FAILURE` when Shipmondo fails |
| `Controller\Shop\GetPickupPointsAction::__construct($shippingMethodRepository, $cartContext, $client, $twig)` | `__construct($shippingMethodRepository, $cartContext, $client, $twig, LoggerInterface $logger)` |

New: `Failure\ShipmondoFailure`, which `ShipmondoFailure::of(\Throwable $exception)` sorts by the SDK exception: its
`kind`, `statusCode`, `error` (Shipmondo's message) and `exception`, its `logContext()`, and its explanation (it is a
`TranslatableInterface`). And the `Failure\ShipmondoFailureKind` enum.

### 10. The admin Shipmondo page can remove a mapping

In 1.x a payment gateway or shipment template mapping on the admin Shipmondo page could be set and changed, but not
removed ([#18](https://github.com/Setono/SyliusShipmondoPlugin/issues/18)): the empty payment gateway choice was skipped
when saving, and a shipping method whose shipment templates were all deselected wasn't submitted at all. In 2.x:

- choosing no payment gateway removes the payment method's mapping (its Shipmondo id becomes `null`);
- deselecting every shipment template of a shipping method removes them all, also when every shipping method is cleared
  in the same save;
- the payment gateway dropdowns are clearable: a mapped one shows a clear icon, since the dropdown shows its empty
  choice only as placeholder text.

A mapping to a payment gateway or shipment template that Shipmondo doesn't list, e.g. one deleted in Shipmondo, is shown
as "Not in Shipmondo (id …)", selected, so saving the form keeps it until you remove it.

**If you override `@SetonoSyliusShipmondoPlugin/admin/shipmondo/index.html.twig`:** next to each shipping method's
select, add `<input type="hidden" name="listed_shipping_methods[]" value="{{ shippingMethod.id }}">`, which tells the
page which shipping methods the form lists. Without it, shipment templates can still be mapped, but not all removed.
Pass `clearable: true` when you turn the selects into dropdowns. And show a mapping Shipmondo doesn't list as a selected
option (`setono_sylius_shipmondo.ui.not_in_shipmondo`, which takes `%id%`), or saving the form removes it. The template
gets these mappings as variables (section 14).

### 11. A sales order's amounts are worked out by one rule, so an order with a free item uploads

In 1.x the order lines, shipping and payment details mappers each divided the VAT by the amount excluding VAT, and the
order lines mapper didn't guard 0: an order with a free item, such as a gift or an item with a 100 % discount, failed
to upload with a division by zero ([#33](https://github.com/Setono/SyliusShipmondoPlugin/issues/33)). In 2.x all three
use `DataMapper\AmountWithVat`, whose VAT percent is `0` when there is no amount excluding VAT. Every other amount is
sent as before.

New: `DataMapper\AmountWithVat` (`fromAmountIncludingVat()`, `fromAmountExcludingVat()`, and the formatted
`amountExcludingVat()`, `vatAmount()`, `amountIncludingVat()` and `vatPercent()`), for a data mapper of your own.

### 12. The shipment template is chosen by where the store ships from

In 1.x a sales order's shipment template was only chosen among templates that ship from Denmark: the sender country was
hard-coded to `DK`, so a store that ships from any other country got none
([#35](https://github.com/Setono/SyliusShipmondoPlugin/issues/35)). In 2.x it is chosen among the shipping method's
allowed shipment templates that ship to the shipping address's country, from the store's country:

- when those templates all ship from one country, from that country, also when the store's company is registered in
  another one;
- when they ship from several countries, from the country of the channel's shop billing data. **Set it** if a shipping
  method allows shipment templates to one country from several countries; without it, no shipment template is chosen.

The shipment templates are also fetched from Shipmondo once until `Provider\SetupProvider` is reset, instead of
for every order: once per upload run on the command line, and once per request or message, since the provider is tagged
`kernel.reset` (it implements `Symfony\Contracts\Service\ResetInterface`, so `symfony/service-contracts` is now
required). A worker started with `messenger:consume --no-reset` keeps them until it stops, so restart it after changing
the shipment templates or payment gateways in Shipmondo. An order whose shipping method allows no shipment template
doesn't fetch them at all.

### 13. Every service is named after its class

Every service the plugin defines now has its class's fully qualified name as its id, instead of a
`setono_sylius_shipmondo.*` id ([#41](https://github.com/Setono/SyliusShipmondoPlugin/issues/41)). That includes the
two services that are instances of the SDK's classes, the client (`Setono\Shipmondo\Client\Client`) and the SDK's
webhook parser (`Setono\Shipmondo\Webhook\WebhookParser`). **If you reference, decorate or replace a service by its
1.x id**, in your service configuration, routes or code (e.g. `$container->get()` in a test), use its new id:

| 1.x id | 2.x id |
|---|---|
| `setono_sylius_shipmondo.client` | `Setono\Shipmondo\Client\Client` |
| `setono_sylius_shipmondo.command.register_webhooks` | `Setono\SyliusShipmondoPlugin\Command\RegisterWebhooksCommand` |
| `setono_sylius_shipmondo.command.upload_orders` | `Setono\SyliusShipmondoPlugin\Command\UploadOrdersCommand` |
| `setono_sylius_shipmondo.controller.admin.shipmondo` | `Setono\SyliusShipmondoPlugin\Controller\Admin\ShipmondoController` |
| `setono_sylius_shipmondo.controller.debug_webhook` | `Setono\SyliusShipmondoPlugin\Controller\DebugWebhookController` |
| `setono_sylius_shipmondo.controller.shop.get_pickup_points` | `Setono\SyliusShipmondoPlugin\Controller\Shop\GetPickupPointsAction` |
| `setono_sylius_shipmondo.custom_factory.remote_event` | removed (section 4) |
| `setono_sylius_shipmondo.data_mapper.sales_order.composite` | `Setono\SyliusShipmondoPlugin\DataMapper\CompositeSalesOrderDataMapper` |
| `setono_sylius_shipmondo.data_mapper.sales_order.default` | `Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapper` |
| `setono_sylius_shipmondo.data_mapper.sales_order.order_lines` | `Setono\SyliusShipmondoPlugin\DataMapper\OrderLinesSalesOrderDataMapper` |
| `setono_sylius_shipmondo.data_mapper.sales_order.payment_details` | `Setono\SyliusShipmondoPlugin\DataMapper\PaymentDetailsSalesOrderDataMapper` |
| `setono_sylius_shipmondo.data_mapper.sales_order.service_point` | `Setono\SyliusShipmondoPlugin\DataMapper\ServicePointDataMapper` |
| `setono_sylius_shipmondo.data_mapper.sales_order.shipment_template` | `Setono\SyliusShipmondoPlugin\DataMapper\ShipmentTemplateSalesOrderDataMapper` |
| `setono_sylius_shipmondo.data_mapper.sales_order.shipping` | `Setono\SyliusShipmondoPlugin\DataMapper\ShippingSalesOrderDataMapper` |
| `setono_sylius_shipmondo.event_subscriber.add_menu` | `Setono\SyliusShipmondoPlugin\EventSubscriber\AddMenuSubscriber` |
| `setono_sylius_shipmondo.event_subscriber.grid.add_pickup_point_delivery_to_shipping_method_grid` | `Setono\SyliusShipmondoPlugin\EventSubscriber\Grid\AddPickupPointDeliveryToShippingMethodGridSubscriber` |
| `setono_sylius_shipmondo.event_subscriber.grid.add_shipmondo_state_column_to_order_grid` | `Setono\SyliusShipmondoPlugin\EventSubscriber\Grid\AddShipmondoToOrderGridSubscriber` (renamed, section 24) |
| `setono_sylius_shipmondo.form.type_extension.shipment` | `Setono\SyliusShipmondoPlugin\Form\Extension\ShipmentTypeExtension` |
| `setono_sylius_shipmondo.form.type_extension.shipping_method` | `Setono\SyliusShipmondoPlugin\Form\Extension\ShippingMethodTypeExtension` |
| `setono_sylius_shipmondo.message.command_handler.register_webhooks` | removed: the registrar registers the webhooks and records the registration (section 20) |
| `setono_sylius_shipmondo.message.command_handler.upload_order` | `Setono\SyliusShipmondoPlugin\Message\CommandHandler\UploadOrderHandler` |
| `setono_sylius_shipmondo.provider.pre_qualified_uploadable_orders` | `Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProvider` |
| `setono_sylius_shipmondo.registrar.webhook` | `Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrar` |
| `setono_sylius_shipmondo.twig.extension` | `Setono\SyliusShipmondoPlugin\Twig\Extension` |
| `setono_sylius_shipmondo.twig.runtime` | `Setono\SyliusShipmondoPlugin\Twig\Runtime` |
| `setono_sylius_shipmondo.uploader.order` | `Setono\SyliusShipmondoPlugin\Uploader\OrderUploader` |
| `setono_sylius_shipmondo.webhook.webhook_consumer` | `Setono\SyliusShipmondoPlugin\Webhook\WebhookConsumer` |
| `setono_sylius_shipmondo.webhook.webhook_parser` | `Setono\SyliusShipmondoPlugin\Webhook\WebhookParser` |

The services that are new in 2.x are named after their classes too. If you import the plugin's routes, its routes
follow on their own.

New: the services below can also be reached through their interface, a private alias, so you can type-hint the interface
in a service of your own (autowiring) and decorate it:

| Alias id | Service |
|---|---|
| `Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface` | `Setono\SyliusShipmondoPlugin\Canceller\OrderCanceller` |
| `Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface` | `Setono\SyliusShipmondoPlugin\DataMapper\CompositeSalesOrderDataMapper`, which runs every data mapper |
| `Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProviderInterface` | `Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProvider` |
| `Setono\SyliusShipmondoPlugin\Provider\UnlinkedOrdersProviderInterface` | `Setono\SyliusShipmondoPlugin\Provider\UnlinkedOrdersProvider` (section 25) |
| `Setono\SyliusShipmondoPlugin\Provider\OrderIdProviderInterface` | `Setono\SyliusShipmondoPlugin\Provider\OrderIdProvider` (section 25) |
| `Setono\SyliusShipmondoPlugin\Provider\SetupProviderInterface` | `Setono\SyliusShipmondoPlugin\Provider\SetupProvider` (section 14) |
| `Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface` | `Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrar` |
| `Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface` | `Setono\SyliusShipmondoPlugin\Uploader\OrderUploader` |
| `Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface` | `Setono\SyliusShipmondoPlugin\Webhook\OrderResolver` |

The plugin's own services get these through the aliases too, so decorating the interface, or the class, reaches them.
The SDK's interfaces (`Setono\Shipmondo\Client\ClientInterface`, `Setono\Shipmondo\Webhook\WebhookParserInterface`)
get no alias, so they stay free for an application that uses the SDK itself.

**If your application defines a service under one of the new ids**, e.g. its own `Setono\Shipmondo\Client\Client`,
your definition now replaces the plugin's, and the plugin uses it.

Unchanged: the parameters (`setono_sylius_shipmondo.api.*`, `setono_sylius_shipmondo.webhooks.*`), the tags
(`setono_sylius_shipmondo.sales_order_data_mapper`, `setono_sylius_shipmondo.order_webhook_handler`), the
`setono_sylius_shipmondo.command_bus` Messenger bus, the `state_machine.setono_sylius_shipmondo__order` workflow and
the services Sylius defines for the `registered_webhooks` resource
(`setono_sylius_shipmondo.repository.registered_webhooks` and the like).

### 14. Mappings to payment gateways and shipment templates that no longer exist in Shipmondo are flagged

In 1.x nothing noticed when a payment gateway or shipment template that a payment method or shipping method is mapped
to no longer existed in Shipmondo, e.g. because it was deleted there
([#32](https://github.com/Setono/SyliusShipmondoPlugin/issues/32)). An order whose shipping method's shipment templates
were all gone was uploaded without one, and the payment gateway was sent as it was: Shipmondo accepts a sales order
with a payment gateway it doesn't have (checked against its sandbox). In 2.x such a stale mapping is still kept until
you change it, but:

- the admin Shipmondo page flags it: a warning message above the payment methods or the shipping methods names each
  method with a stale mapping, and marks its row. The form is shown whenever there is a stale mapping to remove, also
  when Shipmondo has no payment gateway or shipment template left;
- the upload of an order whose shipping method allows shipment templates that no longer exist logs a **warning**, and
  chooses the order's shipment template among the others, if any;
- the upload of an order whose payment method is mapped to a payment gateway that no longer exists logs an **error**,
  since Shipmondo may not capture the order's payment, and uploads the order with that payment gateway as before. It
  checks only the payment gateway the sales order carries (section 21). **When you see it, map the payment method to
  an existing payment gateway**, or remove the mapping.

To tell, the upload now also fetches the payment gateways from Shipmondo when the sales order carries one, once per
upload run (see section 12). Like any call to Shipmondo during an upload, a failure to fetch them settles the order
by its kind (section 6). A payment method without a mapping now sends no `payment_gateway_id` instead of an empty one;
Shipmondo accepts both.

New: `Provider\SetupProviderInterface`, implemented by `Provider\SetupProvider`, which fetches the payment gateways and
shipment templates and tells the stale mappings (`stalePaymentGateway()`, `staleShipmentTemplates()`). Its service is
reached through the interface, a private alias (section 13), so decorate the interface to change it, e.g. to keep the
lists in a cache across a worker's messages. Changed, only if you construct these (final) classes yourself:

- `DataMapper\PaymentDetailsSalesOrderDataMapper` takes a `SetupProviderInterface` and a `Psr\Log\LoggerInterface`;
  it took no arguments;
- `DataMapper\ShipmentTemplateSalesOrderDataMapper` takes a `SetupProviderInterface` and a `Psr\Log\LoggerInterface`
  instead of the SDK's `Client`, and no longer implements `ResetInterface`: `SetupProvider` does;
- `Controller\Admin\ShipmondoController` takes a `SetupProviderInterface` instead of the SDK's `Client`.

**If you override `@SetonoSyliusShipmondoPlugin/admin/shipmondo/index.html.twig`:** it gets two new variables,
`stalePaymentGateways` (each payment method with a stale mapping, with the id of its payment gateway) and
`staleShipmentTemplates` (each shipping method with stale mappings, with the ids of those shipment templates). Both are
`\SplObjectStorage`s keyed by the method, e.g. `stalePaymentGateways[paymentMethod] is defined` and
`staleShipmentTemplates[shippingMethod] ?? []`. Each is empty when the page couldn't fetch its list from Shipmondo. The
warnings' texts are `setono_sylius_shipmondo.ui.stale_payment_gateways` and
`setono_sylius_shipmondo.ui.stale_shipment_templates`. Show a table's form also when Shipmondo has none left but there
are stale mappings (e.g. `shipmondoPaymentMethods|length > 0 or stalePaymentGateways|length > 0`), or a stale mapping
can't be removed, and its warning or error is logged for every upload.

### 15. The admin Shipmondo page says when it couldn't fetch a list, instead of that Shipmondo has none

In 1.x the admin Shipmondo page fetched the payment gateways and then the shipment templates, and a table whose list
couldn't be fetched said that Shipmondo had none and sent you to Shipmondo to create one. When the payment gateways
failed, the shipment templates weren't fetched either, so both tables said so, and the flash always named both lists
([#57](https://github.com/Setono/SyliusShipmondoPlugin/issues/57)). In 2.x it fetches each list on its own:

- a table whose list couldn't be fetched says so, e.g. "The payment gateways could not be loaded from Shipmondo, so the
  payment methods can't be mapped now.", and shows no form, since without the list the page can't tell a mapping from a
  stale one;
- the other table works as usual, e.g. the shipping methods can be mapped when only the payment gateways couldn't be
  fetched;
- the error flash names what couldn't be fetched, and why: one flash for both lists when they failed for the same
  reason, such as a credentials failure, and one per list otherwise. Each failure is logged as a warning.

When Shipmondo doesn't answer, the page now waits for both calls, so it can take up to twice the HTTP client's time
limit (section 22) before it shows.

**If you override `@SetonoSyliusShipmondoPlugin/admin/shipmondo/index.html.twig`:** it gets two new booleans,
`paymentGatewaysFetched` and `shipmentTemplatesFetched`. When one is false, its list and its stale mappings are empty:
show `setono_sylius_shipmondo.ui.payment_gateways_not_loaded` or
`setono_sylius_shipmondo.ui.shipment_templates_not_loaded` instead of the table's form or its text saying Shipmondo has
none.

New translation keys: `setono_sylius_shipmondo.payment_gateways_not_fetched` and
`setono_sylius_shipmondo.shipment_templates_not_fetched` in the `flashes` domain (both take `%reason%`), and
`setono_sylius_shipmondo.ui.payment_gateways_not_loaded` and `setono_sylius_shipmondo.ui.shipment_templates_not_loaded`
in the `messages` domain. `setono_sylius_shipmondo.shipmondo_data_not_fetched` is now flashed only when both lists
failed for the same reason.

### 16. The sales order's shipping line counts the shipping promotions

In 1.x the shipping line of a sales order stated the shipping method's charge, before any shipping promotion, so with a
promotion such as free shipping or 20 % off the shipping it had the wrong price and VAT percent, and the order lines
added up to more than the customer paid ([#52](https://github.com/Setono/SyliusShipmondoPlugin/issues/52)). E.g. 50.00
shipping less a 10.00 promotion, with 25 % VAT included, was sent as 42.00 at a VAT percent of about 0.19. In 2.x the
shipping line states what the customer pays for the shipping: the shipping charge plus the shipment's
`order_shipping_promotion` adjustments, excluding VAT, with the VAT percent of that amount (32.00 at 0.25 in the
example). Shipping made free by a promotion is sent as 0.00 at 0. Shipping without a promotion is sent as before.

**If you listen to `ShippingOrderLineMappedEvent`:** its `adjustment` is still the shipping adjustment, whose amount
doesn't include the shipping promotions. Read the price from the event's `orderLine`.

### 17. Registering the webhooks replaces only this shop's webhooks

In 1.x registering the webhooks first deleted every webhook in the Shipmondo account whose name starts with the name
prefix (`setono_sylius_shipmondo.webhooks.name_prefix`, `Setono Sylius Shipmondo Plugin` by default). When two shops
with the plugin shared a Shipmondo account, registering in one deleted the other's webhooks, and the other silently
stopped getting webhooks until it registered again
([#44](https://github.com/Setono/SyliusShipmondoPlugin/issues/44)). In 2.x a webhook's name carries the shop's id,
e.g. `Setono Sylius Shipmondo Plugin [3fd02dbf] - [orders:delete]`, and registering deletes only the webhooks whose
name starts with the name prefix and that:

- carry this shop's id, wherever they are sent, e.g. to the shop's address before it changed; or
- are sent to this shop's webhook endpoint (the `_webhook_controller` route with `type: shipmondo`), e.g. the ones
  registered before webhooks carried the shop's id.

The shop's id is 8 hexadecimal characters derived from the webhooks key (`SHIPMONDO_WEBHOOKS_KEY`), which give nothing
of the key away. So **give each shop on a Shipmondo account its own webhooks key**: shops with the same key get the
same id, and registering in one deletes the other's webhooks again. A new key gives the shop a new id: registering
then still replaces the webhooks sent to the shop's endpoint, but not ones sent to an earlier address.

**Re-run `setono:sylius-shipmondo:register-webhooks`** after upgrading (section 4 asks for it too): the webhooks'
names changed, so the admin Shipmondo page asks to register them again. The webhooks registered before carry no id, so
registering replaces them only when they are sent to the endpoint it registers now. Delete the ones it leaves in
Shipmondo (Settings > Integrations > Webhooks): the ones for an address the shop no longer has, and the ones for
another address of the shop, which would send the shop every webhook twice. That happens when they were registered
with the admin's "Register webhooks" button under one host, and the command registers them under another (section 18
says which).

A shop still on 1.x deletes every webhook with the name prefix when it registers, the other shops' included, so
upgrade every shop that shares the Shipmondo account. A shop that set a name prefix of its own to share the account can
keep it. If it goes back to the default, registering leaves the webhooks with its old prefix: delete them in
Shipmondo.

### 18. Registering the webhooks from the command line uses the channels' hostname

In 1.x `setono:sylius-shipmondo:register-webhooks` registered the webhooks at the endpoint the router generates on the
command line, where there is no request to take the host from: at the router's default URI
(`framework.router.default_uri`), or at `http://localhost/webhook/shipmondo` when the app doesn't set one. Shipmondo
can't reach localhost, so it sent the shop no webhooks, and the command said nothing
([#45](https://github.com/Setono/SyliusShipmondoPlugin/issues/45)). Registering replaces the shop's webhooks (section
17), so it also replaced the ones that worked. In 2.x the command registers the webhooks at:

1. `https://<hostname>`, for the hostname passed with the new `--hostname` option, e.g. `--hostname=shop.example.com`
   registers `https://shop.example.com/webhook/shipmondo`;
2. else `https://<hostname>`, for the enabled channels' hostname. When the channels have several, the command asks
   which one, or, when it can't ask (`--no-interaction`), fails and lists them;
3. else the router's default URI, path included, as in 1.x.

A hostname is registered at its root, on https's own port: the port and path of `framework.router.default_uri` are
not added to it.

It skips a hostname Shipmondo can't reach, such as `localhost` (the hostname Sylius's fixtures give a channel), a name
under `localhost` or a `127.x.x.x` address, and one that isn't a hostname, e.g. with a port or a scheme. When it finds
none, it fails without registering, so the webhooks registered before are kept.

**If you set `framework.router.default_uri` for the command:** the channels' hostname now comes first. Check that your
enabled channels have the shop's hostname, or pass `--hostname`. **If your channels have several hostnames:** pass
`--hostname` where the command can't ask, e.g. in a deploy script or a cron job, or it fails.

The admin's "Register webhooks" button registers the webhooks at the host the admin is on, unless Shipmondo can't
reach it (section 19).

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| `Command\RegisterWebhooksCommand::__construct(MessageBusInterface $commandBus)` | `__construct(Registrar\WebhookRegistrarInterface $webhookRegistrar, Sylius\Component\Channel\Repository\ChannelRepositoryInterface $channelRepository, Symfony\Component\Routing\Generator\UrlGeneratorInterface $urlGenerator, TranslatorInterface $translator, LoggerInterface $logger)`, given `sylius.repository.channel` and `router` (the registrar is section 20's); it also returns `Command::FAILURE` when it finds no hostname to register the webhooks at |

### 19. Registering the webhooks refuses an endpoint Shipmondo can't reach

In 1.x the admin's "Register webhooks" button registered the webhooks at the endpoint the router generates from the
request, so at the host the admin is opened at, even one Shipmondo can't reach, such as `localhost` or the
`https://127.0.0.1:8000` of `symfony serve` ([#62](https://github.com/Setono/SyliusShipmondoPlugin/issues/62)).
Registering replaces the shop's webhooks (section 17). So a developer who ran a copy of the shop locally, with its
production credentials and webhooks key, replaced the production shop's working webhooks, and the button said
"Webhooks registered successfully".

In 2.x `WebhookRegistrar::register()` registers nothing when Shipmondo can't reach the webhooks' endpoint: when its host
is `localhost`, a name under `localhost`, a `127.x.x.x` address, or not a hostname, e.g. an IPv6 address. It throws
`Setono\SyliusShipmondoPlugin\Registrar\UnreachableWebhookEndpointException`, which carries the `endpoint`, before it
asks Shipmondo anything, so the webhooks registered before are kept. The button flashes why. The rule is the one
`setono:sylius-shipmondo:register-webhooks` chooses a hostname by (section 18), so the command fails with its own
advice before it gets that far. Should a registrar refuse the endpoint anyway, e.g. one you decorated, the command
prints why and fails.

**If you register the webhooks from your own code** (with `WebhookRegistrarInterface::register()`, see section 20):
expect the exception, and register the webhooks at the shop's hostname.

New translation key: `setono_sylius_shipmondo.webhooks_not_registered_at_unreachable_endpoint` in the `flashes` domain
(it takes `%endpoint%`).

### 20. Registering the webhooks happens right away, not through the command bus

In 1.x the admin's "Register webhooks" button and `setono:sylius-shipmondo:register-webhooks` dispatched
`Message\Command\RegisterWebhooks` on the `setono_sylius_shipmondo.command_bus` bus. Messenger routes a command by its
class, so an app that routes it to an asynchronous transport, e.g. with a catch-all `'*': async` route or by routing
`Message\Command\CommandInterface`, had a worker register the webhooks
([#64](https://github.com/Setono/SyliusShipmondoPlugin/issues/64)). The button then flashed "Webhooks registered
successfully" and the command succeeded before anything was registered, also when registering then failed, e.g. with a
credentials failure. And the worker retried failures that trying again can't fix, until they ended in the failure
transport.

In 2.x both call `Registrar\WebhookRegistrarInterface::register()` directly, whatever your Messenger routing, so the
button flashes how it went, and the command prints it and fails (section 9). `register()` now also records the
registration, which the admin's Shipmondo page reads to say when the webhooks were registered. It records it once
Shipmondo has registered every webhook, as 1.x did.

**If you dispatch `RegisterWebhooks` from your own code:** call `WebhookRegistrarInterface::register()` instead. It
throws `UnreachableWebhookEndpointException` (section 19) and the SDK's `ShipmondoException` as they are, not wrapped
in a `HandlerFailedException`. **If you implement or decorate `WebhookRegistrarInterface`:** record the registration in
`register()`, or call the inner registrar's, or the admin's Shipmondo page says the webhooks aren't registered. **If your Messenger routing names `RegisterWebhooks`:** remove that route, since the
class no longer exists. Routing `CommandInterface` now routes `UploadOrder` and `DeleteOrder` only.

**If you configure the `setono_sylius_shipmondo.command_bus` bus's middleware yourself:** the plugin no longer puts
`router_context` on it. It only carried the router's context to a worker that registered the webhooks.

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| `Message\Command\RegisterWebhooks` and `Message\CommandHandler\RegisterWebhooksHandler` | removed: call `Registrar\WebhookRegistrarInterface::register()`, which also records the registration |
| `Registrar\WebhookRegistrar::__construct(...)` | takes the `Repository\RegisteredWebhooksRepositoryInterface` and the `Sylius\Component\Resource\Factory\FactoryInterface` of the `registered_webhooks` resource (`setono_sylius_shipmondo.repository.registered_webhooks` and `setono_sylius_shipmondo.factory.registered_webhooks`) after `$handlers` (section 4) |
| `Controller\Admin\ShipmondoController::__construct($registeredWebhooksRepository, MessageBusInterface $commandBus, $webhookRegistrar, ...)` | takes no command bus |
| `Command\RegisterWebhooksCommand::__construct(MessageBusInterface $commandBus, ...)` | takes a `Registrar\WebhookRegistrarInterface` in its place |

### 21. The sales order's authorized amount is what its gateway payments hold

In 1.x the payment details of a sales order took the authorized amount from the order total, and the payment method and
payment gateway from the order's first payment, whatever its state
([#15](https://github.com/Setono/SyliusShipmondoPlugin/issues/15)). So an order of 140.77 paid with a 30.00 gift card
and 110.77 authorized by a card payment claimed an authorization of 140.77, and a capture of it asked the payment
provider for more than it held. An order paid with gift cards alone claimed an authorization that no payment gateway
held. And an order whose first payment failed, and which the customer then paid with another payment method, was sent
with the failed payment's method and payment gateway.

In 2.x only the order's authorized and completed payments count; a new, processing, failed, cancelled or refunded
payment is ignored. A **gateway payment** is one of them made with a payment method mapped to a payment gateway (a
stale mapping included, see section 14):

- when the gateway payments all go through one payment gateway, `payment_gateway_id` is that payment gateway, and
  `authorized_amount` is the sum of their amounts (110.77 in the example);
- when they go through several payment gateways, the sales order carries neither, since it carries only one payment
  gateway, and the upload logs a **warning** naming the order and its payment gateways: Shipmondo can't capture the
  order's payment;
- when there is no gateway payment, e.g. an order paid with gift cards alone, or by a payment method mapped to no
  payment gateway, the sales order carries neither, and nothing is logged. **A shop that maps no payment method to a
  payment gateway sent the order total as `authorized_amount` before, and now sends none.**

`payment_method` (a label in Shipmondo) is the name of the method of the largest gateway payment through the payment
gateway that is sent, or else of the largest authorized or completed payment, the earliest of them on a tie. It is left
out when the order has no authorized or completed payment. The error about a stale payment gateway (section 14) is
logged for the payment gateway that is sent. `amount_including_vat`, `amount_excluding_vat`, `vat_amount`,
`vat_percent` and `currency_code` still describe the order total.

**If you adjust `authorizedAmount` yourself**, e.g. in a `SalesOrderMappedEvent` listener or a decorator of
`SalesOrderDataMapperInterface` that deducts the gift card payments, **remove that in the same deploy as this
upgrade**. The plugin now leaves them out itself, so a listener that deducts them again sends too little (80.77 in the
example, and Shipmondo captures 30.00 less than the card payment holds). And for an order paid with gift cards alone
`authorizedAmount` is now `null`, which a deduction may turn into a negative amount.

### 22. Requests to Shipmondo have time limits

In 1.x the plugin built the Shipmondo SDK client without an HTTP client, so the SDK used whichever PSR-18 client it
discovered among the shop's packages. Symfony's gave up on a request only after `default_socket_timeout` (60 s by
default) without any data, and had no limit on a request as a whole, and Guzzle's had no limit at all
([#31](https://github.com/Setono/SyliusShipmondoPlugin/issues/31)). A slow Shipmondo stalled the shop's pickup point
search while a customer waited in checkout, the upload run and Messenger workers, the admin Shipmondo page, registering
the webhooks, and cancelling an order in the admin, which deletes its sales order inside Sylius's cancel transaction.

In 2.x the plugin prepends two framework scoped HTTP clients. Its Shipmondo client (the `Setono\Shipmondo\Client\Client`
service) sends its requests through `HttpClient\ShipmondoHttpClient`, which sends a pickup point search through the
PSR-18 version of the one and every other call through the PSR-18 version of the other:

| HTTP client | Used by | `timeout`: the longest wait with no data at all | `max_duration`: the most one request may take |
|---|---|---|---|
| `setono_sylius_shipmondo.http_client` | every call to Shipmondo but the pickup point search: uploads, sales order deletions, the admin Shipmondo page, webhook registration | 10 s | 30 s |
| `setono_sylius_shipmondo.pickup_points_http_client` | the shop's pickup point search | 5 s | 10 s |

Both are scoped to the Shipmondo API in production and in the sandbox (`https://(app|sandbox)\.shipmondo\.com/`) and
don't retry a failed request.

- **The plugin requires `symfony/http-client`**, and `framework.http_client` must be enabled, which it is by default
  once `symfony/http-client` is installed. With `framework.http_client.enabled: false` the container doesn't build
  (a non-existent service `psr18.setono_sylius_shipmondo.http_client`).
- **To change a limit**, name only what you change in your own framework config. The configs merge per key, so the
  plugin's scope, the other limit and the retries staying off are kept:

  ```yaml
  framework:
      http_client:
          scoped_clients:
              setono_sylius_shipmondo.http_client:
                  max_duration: 60
  ```

- **Your `framework.http_client.default_options` apply to the requests to Shipmondo now**, e.g. a proxy, headers or TLS
  settings, under the plugin's limits.
- **Retries stay off.** The plugin retries on its own terms: the next upload run retries an upload that failed
  temporarily (section 6), and a worker retries a sales order deletion (section 5). Symfony's `retry_failed` repeats
  even a POST, e.g. on 429, 502 and 503, and repeating the creation of a sales order can create it twice. A
  `default_options.retry_failed` of yours doesn't reach the plugin's HTTP clients, unless you configure it (e.g. its
  `max_retries`, not just `retry_failed: true`) in a config file that also names one of them: Symfony then copies the
  default retry onto that client, whatever the plugin prepends. **So when one file of yours configures
  `default_options.retry_failed` and overrides one of the plugin's clients, repeat `retry_failed: false` there:**

  ```yaml
  framework:
      http_client:
          default_options:
              retry_failed:
                  max_retries: 3
          scoped_clients:
              setono_sylius_shipmondo.http_client:
                  max_duration: 60
                  retry_failed: false
  ```

- **A request that runs into its limit** is a Shipmondo failure like any other (section 9):
  - When Shipmondo doesn't answer in time, it is a **temporary failure**, as a network error is: the shop's pickup point
    endpoint answers HTTP 502 and the picker says the pickup points couldn't be loaded, and the admin says Shipmondo
    couldn't be reached. When the call that creates a sales order runs out of time, Shipmondo may have created the
    sales order before it did, so the upload makes the order `unlinked`, and the upload run looks the sales order up
    later instead of creating a second one (section 25). The shorter the limit, the more orders that takes. When a read
    before that call runs out of time, the order goes back to pending.
  - When Shipmondo answers a call that changes something, creating or deleting a sales order, with a success, but
    the rest of its answer doesn't arrive, e.g. in time, it is an **unread success** (section 23): Shipmondo did what
    was asked, but what it answered is lost. **An upload then makes the order `unlinked`** instead of sending it back
    to pending, since Shipmondo created the sales order, and a sales order deletion counts as done, since the sales
    order is gone.
  - When the success of a read doesn't arrive in full, e.g. of the pickup point search, or of the payment gateways and
    shipment templates an upload reads, it is a temporary failure, as when Shipmondo doesn't answer at all: reading
    again changes nothing.
  - When Shipmondo answers with an error status but the rest of its answer doesn't arrive, the failure is sorted by the
    status, as before, without Shipmondo's message.

This needs `setono/shipmondo-php-sdk` 2.0.0-beta.4 or later, which reads the whole of Shipmondo's answer in the call, so
a failure while Shipmondo is still answering is an exception too, and which tells a success whose answer didn't arrive
in full (`ConnectionException::getResponse()`) from no answer at all.

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| the `Setono\Shipmondo\Client\Client` service discovers its HTTP client | it gets the new `HttpClient\ShipmondoHttpClient` service, its 4th argument, which sends a pickup point search through `psr18.setono_sylius_shipmondo.pickup_points_http_client` and every other call through `psr18.setono_sylius_shipmondo.http_client` |
| `Failure\ShipmondoFailure::of()` sorts every `ConnectionException` as `temporary_failure` | a `ConnectionException` that carries a success (`getResponse()`) to a call other than a GET is an `unread_success` (section 23); it has that success's `statusCode`, also when it is a `temporary_failure` |

New: `DependencyInjection\SetonoSyliusShipmondoExtension::HTTP_CLIENT` and `::PICKUP_POINTS_HTTP_CLIENT`, the names of
the two HTTP clients (their PSR-18 versions are `psr18.<name>`).

### 23. An upload whose success can't be read makes the order unlinked

Shipmondo can answer the upload of an order with a success the plugin can't read: the answer is cut off, e.g. because
it doesn't arrive in time (section 22), or its body can't be decoded or mapped. Shipmondo has then created the sales
order, but the plugin doesn't know its id. Such an upload used to make the order `failed`, a state the plugin treats as
one in which Shipmondo has no sales order for the order: cancelling it in Sylius made it `cancelled` right away, while its
sales order stayed in Shipmondo, where it could still be shipped
([#69](https://github.com/Setono/SyliusShipmondoPlugin/issues/69)). Why it gets a state of its own is recorded in
[ADR 0002](docs/adr/0002-an-unread-success-makes-the-order-unlinked.md).

- **Such a call is a new kind of Shipmondo failure**, `unread_success` (`ShipmondoFailureKind::UnreadSuccess`, section
  9): a success to a call that changes something (anything but a GET) whose answer was cut off (the SDK's
  `ConnectionException` with a `getResponse()`) or couldn't be read (`MalformedResponseException`, including
  `MappingException`). For a GET nothing changes.
- **An upload that ends so makes the order `unlinked`**, a new Shipmondo state (`OrderInterface::SHIPMONDO_STATE_UNLINKED`),
  through the new `complete_upload_unlinked` transition (`OrderWorkflow::TRANSITION_COMPLETE_UPLOAD_UNLINKED`, from
  `uploading_to_shipmondo`), and logs a warning. The order isn't linked to a sales order. For the upload run's stop rule
  for failed uploads it counts as an upload Shipmondo accepted, and the run goes on; three in a row make the command fail
  (section 28).
- **Only a sales order lookup moves an order out of `unlinked`** (section 25): not an admin's `retry`, which would
  upload it a second time, nor `cancel`. Until the lookup links it, webhooks about its sales order find no order, since
  none is linked to it.
- **Cancelling an unlinked order in Sylius leaves it `unlinked`**, since its sales order can't be deleted without its id:
  the sales order lookup deletes it once it finds it (section 25). The same happens to an order cancelled while it was
  uploading, whose upload makes it `unlinked`.
- **A sales order deletion that ends in an unread success counts as done**, like a 404: the sales order is gone, so the
  order is reset and then cancelled (section 5). Before, it counted as failed, and the order stayed uploaded and linked.
- **Registering the webhooks** explains an unread success like any other failure (section 9): register them again.
- The order grid shows an unlinked order with an orange label. **If you override the Shipmondo state label
  templates**, add `unlinked.html.twig` if you want your own.

New translation keys: `setono_sylius_shipmondo.shipmondo_failure.unread_success` and `setono_sylius_shipmondo.ui.unlinked`
in the `messages` domain.

This needs `setono/shipmondo-php-sdk` 2.0.0-rc.2 or later, whose exceptions for a success it can't read carry the
request Shipmondo answered (`ResponseAwareException::getRequest()`), so the plugin can tell a call that changes something
from a read.

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| | `Canceller\OrderCanceller::__construct($commandBus, $orderWorkflow, Psr\Log\LoggerInterface $logger)` |

New: the `OrderInterface::SHIPMONDO_STATE_UNLINKED` and `OrderWorkflow::TRANSITION_COMPLETE_UPLOAD_UNLINKED`
constants, and the `ShipmondoFailureKind::UnreadSuccess`, `UploadOutcome::UnreadSuccess` and `Cancellation::LeftUnlinked`
cases. If your code matches on the Shipmondo states, the failure kinds, `UploadOutcome` or `Cancellation`, handle them.

### 24. An admin can retry the upload of a failed order

A `failed` order used to be a dead end: nothing moved it out of `failed` but cancelling it, and the admin showed its
Shipmondo state only as a column in the order grid
([#30](https://github.com/Setono/SyliusShipmondoPlugin/issues/30)). Since an upload whose success can't be read makes
the order `unlinked` (section 23), Shipmondo has no sales order for a `failed` order, so retrying its upload can't create
a second one.

- **The `retry` transition (`OrderWorkflow::TRANSITION_RETRY`) now leads to `pending` from `failed` too**, not only from
  `uploading_to_shipmondo`. It still means the same: back to `pending`, so the next upload run tries again. The upload
  still applies it only to an uploading order.
- **Retrying never calls Shipmondo.** It makes the order `pending`, and the next upload run uploads it if it still
  qualifies for upload, including any conditions you add through `PreQualifiedUploadableOrdersQueryBuilderCreatedEvent`.
  An order that no longer qualifies stays `pending`, like any other pending order. The order is locked and read again
  when it is retried, and one that is no longer `failed` by then, e.g. because it was cancelled meanwhile, is left as it
  is. An uploading order is left to its upload.
- **The order page has a Shipmondo section** in its sidebar, right after the shipments: the order's Shipmondo state,
  with the label the order grid shows, the id of its sales order once it is uploaded, and a "Retry upload" button for a
  `failed` order. It is the `setono_sylius_shipmondo` block (priority 32) of the `sylius.admin.order.show.sidebar`
  template event, rendering `@SetonoSyliusShipmondoPlugin/admin/order/show/_shipmondo.html.twig`. **If you override
  Sylius's order page sidebar**, e.g. its `_content.html.twig`, and no longer render that template event, render the
  plugin's template yourself; to move or drop the section, configure the block under `sylius_ui.events`.
- **The order grid has a "Shipmondo state" filter** (`criteria[shipmondo_state]`) with every Shipmondo state, and a
  **"Retry upload to Shipmondo" bulk action**, which retries the selected orders that are `failed`, leaves the rest as
  they are, and flashes how many it retried and how many it left. Sylius's order grid has no bulk action of its own, so
  the grid now also gets a column of checkboxes to select the orders with. The bulk action's type,
  `setono_sylius_shipmondo_retry_upload`, renders `@SetonoSyliusShipmondoPlugin/admin/order/grid/bulk_action/retry_upload.html.twig`
  (the plugin prepends it to `sylius_grid.templates.bulk_action`).
- **Both actions take a PUT with a CSRF token**, like Sylius's own state transitions in the admin, e.g. cancelling an
  order: `PUT /admin/orders/{id}/retry-upload` (route `setono_sylius_shipmondo_admin_order_retry_upload`, token id: the
  order's id) and `PUT /admin/orders/bulk/retry-upload` (route `setono_sylius_shipmondo_admin_order_bulk_retry_upload`,
  token id `RetryUploadController::BULK_CSRF_TOKEN_ID`, the order ids as `ids[]`). Anyone with admin access may use
  them. Both routes come with the plugin's admin routes, in `routes.yaml` and `routes_no_locale.yaml` alike.
- Each retry is logged at info level, also when the order was left as it was.

New translation keys: `setono_sylius_shipmondo.ui.retry_upload`, `setono_sylius_shipmondo.ui.retry_upload_to_shipmondo`
and `setono_sylius_shipmondo.ui.sales_order_id` in the `messages` domain, and `setono_sylius_shipmondo.order_upload_retried`,
`setono_sylius_shipmondo.order_upload_not_retried`, `setono_sylius_shipmondo.orders_upload_retried` and
`setono_sylius_shipmondo.orders_upload_not_retried` in the `flashes` domain (the last two are pluralized on `%count%`).

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| `EventSubscriber\Grid\AddShipmondoStateColumnToOrderGridSubscriber` | `EventSubscriber\Grid\AddShipmondoToOrderGridSubscriber`, which adds the column, the filter and the bulk action |
| | new `Uploader\OrderUploaderInterface::retry(OrderInterface $order): bool`, which makes a `failed` order `pending` and says whether it did. Implement it if you implement the interface |

New: `Controller\Admin\RetryUploadController`.

### 25. A sales order lookup settles an unlinked order, and a temporary failure to create the sales order makes the order unlinked

Shipmondo has no idempotency key and accepts two sales orders with the same order id. A temporary failure of the call
that creates the sales order (a 5xx, or no answer, e.g. a timeout, section 22) used to put the order back to `pending`.
If Shipmondo had created the sales order before the failure, the next upload run created a second one, which could be
shipped twice. And nothing moved an `unlinked` order (section 23) on: an admin had to find its sales order in Shipmondo
by hand ([#29](https://github.com/Setono/SyliusShipmondoPlugin/issues/29)). Why the plugin does it this way is
recorded in [ADR 0003](docs/adr/0003-a-sales-order-lookup-settles-every-unlinked-order.md).

- **A temporary failure of the call that creates the sales order makes the order `unlinked`**, as an unread success
  does (section 23), and logs a warning. A temporary failure of a read before that call, e.g. of the payment gateways
  or shipment templates the data mappers fetch, still puts the order back to `pending`, since Shipmondo has no sales
  order then. So do a credentials failure and a 429. The upload run's stop rules count a temporary failure the same
  way, wherever it happens (section 28).
- **Each upload run starts with a sales order lookup** for every order that has been `unlinked` for at least ten
  minutes, newest first, whatever its state in Sylius, before it uploads the pending orders. Shipmondo lists a sales
  order it just created only after a short delay, so the run waits ten minutes (a fixed delay), measured from when the
  order became `unlinked`, which the order now records (see the database migration below). A later change to the order,
  e.g. a payment or a cancel in Sylius, doesn't put its lookup off. So an order whose upload failed temporarily is
  uploaded again at least ten minutes later, not in the next run. Conditions you add through
  `PreQualifiedUploadableOrdersQueryBuilderCreatedEvent` don't narrow the lookups. They run in the upload run, never
  through the command bus, also when you route `UploadOrder` to an async transport.
- **The lookup searches Shipmondo's sales orders by the exact order id the upload sent** (`GET /sales_orders` with
  `order_id`, archived sales orders included), and settles the order by what it finds, locked and read again. An order
  that changed while Shipmondo was searched is left as it is.

  | Sales orders found | The order |
  |---|---|
  | one | is linked to it, and becomes `uploaded_to_shipmondo` through the new `link` transition (`OrderWorkflow::TRANSITION_LINK`, `unlinked` → `uploaded_to_shipmondo`), which needs the sales order id in its context, like `complete_upload` |
  | none: someone deleted it in Shipmondo, or the call that failed didn't create one | goes back to `pending` through `retry`, which now leaves `unlinked` too, and is uploaded again, in the same run if it qualifies for upload |
  | several | stays `unlinked`, and an error names them: they may be duplicates, or another shop's on the same Shipmondo account. Every run looks it up again, so once Shipmondo has only one of them, the order is linked to it |

- **An `unlinked` order that is cancelled in Sylius is settled by its lookup too** (section 7): the sales order the lookup
  finds is deleted, and the order becomes `cancelled`; with none found, it becomes `cancelled` right away; with several,
  it stays `unlinked`. Its lookup is still due ten minutes after the order became `unlinked`, not after the cancel, but
  it waits for the next upload run, and with several matches nothing is deleted. So cancelling it in the admin flashes
  that its sales order will be deleted once an upload run finds it, at least ten minutes after its upload, and to cancel
  it in Shipmondo when the order must not be packed before then, or still shows as Unlinked later.
- **A lookup that fails leaves the order `unlinked`**, logs the failure, and the next run tries again. A credentials
  failure stops the upload run, as for an upload. A 429 stops the lookups, which have their own rate limit in Shipmondo
  (about 100 searches a minute), but the pending orders are still uploaded. **Either way, the command fails**, so your
  cron monitoring notices. A temporary failure counts toward the run's temporary failures in a row, and any other
  failure toward the lookups' failures in a row (section 28), never toward the uploads that make the order `failed`.
- **The lookup matches on the order id alone.** Another shop on the same Shipmondo account that numbers its orders the
  same way can have sales orders with the same order ids: an order then stays `unlinked` with several matches, or, when
  its own sales order is gone, is linked to the other shop's. Give each such shop an order id prefix of its own to
  avoid that (section 27).
- **Webhooks about a sales order that arrive while its order is `unlinked` are lost**, since no order is linked to it
  yet ([#76](https://github.com/Setono/SyliusShipmondoPlugin/issues/76)). That should be rare: a sales order is seldom
  shipped, or its payment captured, within minutes of its upload.

**Run a database migration.** The order now records when its Shipmondo state last changed, which for an `unlinked`
order is when it became `unlinked`, in a new nullable column, `shipmondoStateChangedAt`, mapped in `OrderTrait`. It is
set whenever the Shipmondo state is (`setShipmondoState()`, which every transition of the
`setono_sylius_shipmondo__order` workflow calls), and has no setter of its own. The plugin ships no migration: generate
it with `bin/console doctrine:migrations:diff` as usual. On MySQL it is:

```sql
ALTER TABLE sylius_order ADD shipmondoStateChangedAt DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)';
```

An order has no such time until its Shipmondo state changes after the upgrade. An `unlinked` order without one counts
as unlinked for long enough, so its lookup is due in the next upload run. If your order entity maps the plugin's
columns itself instead of through `OrderTrait`, map this one too.

New translation key: `setono_sylius_shipmondo.order_unlinked_will_be_deleted_in_shipmondo` in the `flashes` domain.

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| `DataMapper\SalesOrderDataMapper::__construct()` | `__construct(Provider\OrderIdProviderInterface $orderIdProvider)` |
| `Uploader\OrderUploader::__construct(...)` | takes the unlinked orders provider and the order id provider too (section 6) |
| | new `Model\OrderInterface::getShipmondoStateChangedAt(): ?\DateTimeImmutable`, when the Shipmondo state last changed, which `OrderTrait` implements. Implement it if your order entity doesn't use the trait |

New: the `OrderWorkflow::TRANSITION_LINK` constant; `Provider\UnlinkedOrdersProviderInterface` (a private alias of
`Provider\UnlinkedOrdersProvider`, section 13), which finds the unlinked orders due a lookup
(`UnlinkedOrdersProvider::LOOKUP_DELAY`); and `Provider\OrderIdProviderInterface` (a private alias of
`Provider\OrderIdProvider`, which gives the order number, after the order id prefix, section 27), the one place the
order id the plugin sends Shipmondo comes from, which the upload and the lookup both use. Decorate or replace it to send
another order id (to put a prefix before the order number, set the `order_id_prefix` option instead), but only while no
order is `unlinked` or `uploading_to_shipmondo`: a lookup searches by the order id it gives now, so an unlinked order's
lookup would find no sales order, and the order would be uploaded a second time. `UploadOutcome::TemporaryFailure` now
leaves an order `unlinked` when the call that creates the sales order failed. If your code relies on `unlinked` being
final, or on `retry` leaving only `uploading_to_shipmondo` and `failed`, adjust it.

### 26. The order records why its upload didn't go through, and the order page shows it

An admin who saw a `failed` order (or an `unlinked` one, or one that keeps going back to `pending`) couldn't tell why
without reading the logs ([#70](https://github.com/Setono/SyliusShipmondoPlugin/issues/70)).

- **The order records the Shipmondo failure that put it in its Shipmondo state**: its kind (`ShipmondoFailureKind`),
  Shipmondo's status code (none when Shipmondo didn't answer) and its message, which is Shipmondo's own message about the
  failure, e.g. which data it found invalid, or else the exception's. The message is cut off at 1,000 characters
  (`RecordedShipmondoFailure::MAX_MESSAGE_LENGTH`), since without Shipmondo's message the exception's holds Shipmondo's
  whole answer, e.g. a proxy's HTML error page, and any bytes that aren't UTF-8 become question marks, so it can always be
  stored. The log keeps the whole exception, as before.
- **Every upload that settles with a Shipmondo failure records it**: one that makes the order `failed` (rejected, not
  found, unexpected), `unlinked` (an unread success, or a temporary failure of the call that creates the sales order),
  or `pending` again (a credentials failure, a 429, or a temporary failure before that call). How a failure settles the
  Shipmondo state is unchanged.
- **The Shipmondo state owns it**, as it owns the link to the sales order (section 7): it is passed in the context of the
  transition that puts the order in its new state, under the new `OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE`, as a
  `Failure\RecordedShipmondoFailure`, and `setShipmondoState()` records it, or clears the one recorded before when the
  context has none. So every later change of the Shipmondo state replaces or clears it: an admin's retry, the next
  upload run claiming the order, a sales order lookup that links the order or puts it back to `pending`, a successful
  upload, a cancel. A lookup that leaves the order `unlinked` keeps it. There is no history and no setter. A context
  that holds anything else under that key makes `setShipmondoState()` throw an `\InvalidArgumentException`.
- **The order page's Shipmondo section shows it** (section 24): the explanation the flashes and the admin Shipmondo page
  give for its kind (the `setono_sylius_shipmondo.shipmondo_failure.<kind>` keys), the status code when there is one,
  when it happened (when the Shipmondo state last changed, section 25), and what happens next: for a `failed` order to
  fix the cause and retry the upload, for an `unlinked` one that there is nothing to retry, since a sales order lookup
  settles it, for a `pending` one that the next upload run uploads it again. An order without a failure shows the section as before. The order grid is
  unchanged. If you override `@SetonoSyliusShipmondoPlugin/admin/order/show/_shipmondo.html.twig`, add the failure
  (`order.shipmondoFailure`) to yours.

**Run a database migration.** The failure is mapped in `OrderTrait` to one new nullable JSON column, `shipmondoFailure`,
with the new Doctrine type `setono_sylius_shipmondo_recorded_shipmondo_failure` (`Doctrine\Type\RecordedShipmondoFailureType`,
which the plugin registers with Doctrine, as it does the pickup point's type, section 8). It holds the kind, the status
code and the message, e.g. `{"kind":"rejected","statusCode":422,"message":"…"}`. The plugin ships no migration: generate
it with `bin/console doctrine:migrations:diff` as usual. On MySQL it is:

```sql
ALTER TABLE sylius_order ADD shipmondoFailure JSON DEFAULT NULL COMMENT '(DC2Type:setono_sylius_shipmondo_recorded_shipmondo_failure)';
```

An order has no failure recorded until an upload settles with one after the upgrade. A stored value the type can't
read as a failure, e.g. one of a kind the plugin doesn't know, reads as none, so the order still loads. If your order
entity maps the plugin's columns itself instead of through `OrderTrait`, map this one too, with that type.

New translation keys in the `messages` domain: `setono_sylius_shipmondo.ui.last_upload`,
`setono_sylius_shipmondo.ui.last_upload_next_step_failed`, `setono_sylius_shipmondo.ui.last_upload_next_step_pending`,
`setono_sylius_shipmondo.ui.last_upload_next_step_unlinked`, `setono_sylius_shipmondo.ui.status_code` and
`setono_sylius_shipmondo.ui.when`.

Only if you extend or call the code involved:

| 1.x | 2.x |
|---|---|
| | new `Model\OrderInterface::getShipmondoFailure(): ?Failure\RecordedShipmondoFailure`, which `OrderTrait` implements. Implement it if your order entity doesn't use the trait, and have your `setShipmondoState()` record the failure in the context |
| | `ShipmondoFailure::trans()` explains the failure through `RecordedShipmondoFailure`, so a flash with a failure's message cuts it off at 1,000 characters too |

New: `Failure\RecordedShipmondoFailure` (`of(ShipmondoFailure)`, translatable), `Doctrine\Type\RecordedShipmondoFailureType`
and the `OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE` constant. If you apply a transition of the `setono_sylius_shipmondo__order`
workflow yourself after a failed call to Shipmondo, pass `RecordedShipmondoFailure::of(ShipmondoFailure::of($e))`
under it, so the order page explains the state.

### 27. A shop can prefix the order ids it sends to Shipmondo

A sales order's order id is the Sylius order number, and Sylius numbers every shop's orders the same way (`000000001`,
`000000002`, …). So shops that share a Shipmondo account sent the same order ids: warehouse staff couldn't tell which
shop a sales order came from, and a sales order lookup (section 25) could find another shop's sales order, then link
the order to it, or delete it when the order was cancelled ([#71](https://github.com/Setono/SyliusShipmondoPlugin/issues/71)).

The new `order_id_prefix` option goes before the order number in the order id the plugin sends Shipmondo. It is empty
by default, so nothing changes unless you set it:

```yaml
# config/packages/setono_sylius_shipmondo.yaml
setono_sylius_shipmondo:
    order_id_prefix: 'WEB-' # the order 000001234 becomes the sales order WEB-000001234
```

- **Give every shop that shares a Shipmondo account its own.** The upload sends the prefixed order id, and a sales
  order lookup searches by it and matches only the sales orders whose order id is exactly that, so it can't find
  another shop's.
- **It shows wherever Shipmondo shows or prints the order id**, which may reach customers.
- **It applies only to the sales orders uploaded after it is set.** The sales orders uploaded before keep their order
  ids, and their orders stay linked to them: webhooks find an order by its link to the sales order, never by its order
  id.
- **Don't set or change it while any order is `unlinked` or `uploading_to_shipmondo`.** A sales order lookup searches
  by the order id the order gets now, not by the one its upload sent. So it would find no sales order, and the order
  would be uploaded a second time. Change it between two upload runs, once the order grid's Shipmondo state filter
  (section 24) finds no such order. The same holds for a decorator of `Provider\OrderIdProviderInterface` (section 25)
  that changes the order id it gives. A decorator keeps working with the option: the plugin's `OrderIdProvider` gives
  it the prefixed order id.

New: the `setono_sylius_shipmondo.order_id_prefix` parameter, which the plugin passes to
`Provider\OrderIdProvider::__construct(string $prefix = '')`. An `order_id_prefix` of `~` (null) is no prefix.

### 28. The upload run stops after temporary failures, failed uploads or failed lookups in a row, and reports unread successes in a row

`setono:sylius-shipmondo:upload-orders` failed only when the upload run stopped early: on a credentials failure, a 429,
or three rejected uploads in a row. A problem that hit every order otherwise went unnoticed by cron monitoring
([#74](https://github.com/Setono/SyliusShipmondoPlugin/issues/74)): during a Shipmondo outage, every lookup and upload
waited out the time limit (section 22), and every upload made its order `unlinked`; a data mapper that failed for every
order made every order `failed`; answers the SDK couldn't read made every order `unlinked`; and lookups that Shipmondo
rejected left every unlinked order `unlinked` for good.

The upload run now keeps four counts, of the sales order lookups and of the uploads it sees, which are those handled
synchronously (the default). How an upload sent to an asynchronous transport goes is unknown to it, as before.

| In a row | Starts the count over | At three |
|---|---|---|
| Temporary failures (a 5xx, or no answer in time, section 22), lookups and uploads alike, including one while a data mapper reads from Shipmondo | any other result of a lookup or an upload. An upload that made no call, because the order changed while it was uploading, leaves the count as it is | **the run stops**: the pending orders stay `pending`, and the unlinked orders `unlinked` |
| Uploads that make the order `failed`: rejected, not found, and **now an unexpected failure too**, e.g. a data mapper that throws | an upload Shipmondo accepted: uploaded, or an unread success | **the run stops**, as after three rejected uploads before |
| Lookups that fail in any way but temporarily, by a 429 or by a credentials failure: rejected, not found, or an unexpected failure, e.g. an answer the SDK can't read | a lookup whose search succeeded, whatever it found | **the lookups stop**, as after a 429, and the pending orders are still uploaded |
| Unread successes (section 23) | only an uploaded order | **the run goes on**, since Shipmondo created the sales orders and lookups link them later, but it reports the problem |

- Each stop, and each problem the run reports, is logged as an error, with the count.
- **The command fails whenever the run reports a problem**: it stopped early, its lookups stopped, or it hit three
  unread successes in a row. Its error names every problem, each with what to do, e.g. for unread successes to check the
  log, the time limits of the requests to Shipmondo (section 22) and the version of the Shipmondo SDK. It stays silent
  otherwise, also after fewer than three of each in a row.
- **Its messages for a 429 changed**: after a 429 in an upload it says that the run stopped because Shipmondo rate
  limited an upload, and after a 429 in a lookup that the lookups stopped and the pending orders were uploaded, each with
  what to check if it keeps happening. Before, both said that Shipmondo rate limited the upload run. If your monitoring
  matches on the command's output, update it.
- **The counts aren't configurable**, and the time limits, the ten minutes before a lookup and the rules for a 429 are
  unchanged.

Only if you extend or call the code involved: `Uploader\OrderUploaderInterface::upload()` now returns an `array`, the
`list<Uploader\UploadRunProblem>` the run reports, in the order they arose, each once, or an empty list when it reports
none, instead of `?Uploader\UploadOutcome`, the outcome that stopped the run (section 6). Implement it so if you
implement the interface, and if you call it, read the problems instead of the outcome.

New: the `Uploader\UploadRunProblem` enum. `CredentialsFailure`, `UploadRateLimited`, `TemporaryFailuresInARow` and
`FailedUploadsInARow` stopped the run; `LookupsRateLimited` and `FailedLookupsInARow` stopped the lookups; and
`UnreadSuccessesInARow` is only reported. The counts live in `Uploader\UploadRun`, which is internal.

### 29. The plugin requires PHP 8.2 and the Shipmondo SDK's 2.0.0-rc.3

The plugin now requires `setono/shipmondo-php-sdk: ^2.0.0-rc.3`, and so **PHP 8.2**, which that release requires. The
SDK maps Shipmondo's answers with Valinor, whose versions before 2.5 leaked memory in long-running processes such as
Messenger workers ([CuyZ/Valinor#800](https://github.com/CuyZ/Valinor/issues/800)); the fixed versions require PHP 8.2.
**Upgrade PHP to 8.2 or newer before you upgrade the plugin.**

With it, registering the webhooks no longer fails when a webhook on the Shipmondo account has an action or resource the
SDK doesn't know, e.g. another integration's: the SDK reads such a value as null, instead of failing to read the whole
list of webhooks.

**Pickup points stored on shipments keep working.** The SDK now spells a pickup point's zip code `zipCode`, also in its
JSON, and reads only that. Every pickup point stored before, by 1.x or by 2.x until now, has `zipcode`, and the plugin's
`Parser\PickupPointParser`, which the shipment's column type and the checkout use, still reads it. Read a stored pickup
point through it, not through the SDK's `PickupPoint::fromArray()`.

**If you override the shop's pickup point list** (`shop/ajax/pickup_points.html.twig`): read `pickupPoint.zipCode`
instead of `pickupPoint.zipcode`.

Only if your own code uses the SDK's classes, e.g. a data mapper of your own, or a `Client` you build yourself:

| SDK 2.0.0-rc.2 | 2.0.0-rc.3 |
|---|---|
| `zipcode` on `Request\SalesOrder\Recipient`, `Sender` and `ServicePoint`, and on `Response\PickupPoint\PickupPoint` (also in its JSON) | `zipCode`; Shipmondo still receives and sends `zipcode` |
| response values cast to the type of the field | not cast: a value of another type than Shipmondo documents fails to map (a `MappingException`) |
| `Response\Webhook\Webhook::$action` and `$resourceName` | nullable: null for a value the SDK doesn't know |
| `Client::configureMapperBuilder()` and `Client::registerNormalizerTransformers()` | removed: the `Client` applies its configuration to whichever Valinor builder it is given |

### 30. Registering the webhooks fixes only the difference, so it can run daily

Registering the webhooks deleted all of the shop's webhooks in Shipmondo and created them again, and recorded a
`RegisteredWebhooks` per version, an md5 of the wanted webhooks. So after an upgrade that changed the actions the webhook
handlers handle, nothing happened until someone registered them again, a webhook deleted or deactivated in Shipmondo
went unnoticed, and registering often meant a moment with no webhooks every time
([#14](https://github.com/Setono/SyliusShipmondoPlugin/issues/14)).

**Registering compares, then fixes the difference.** It fetches the webhooks in Shipmondo, picks the shop's (the rule
of section 17), and compares them with the wanted ones: one per action the webhook handlers handle, on the orders
resource, named as before, active and sent to the endpoint.

- It creates the missing ones. One that is inactive, sent to another endpoint, or differs in its name, action or
  resource counts as missing, and is replaced.
- It deletes the shop's webhooks that aren't wanted, e.g. one for an action no handler handles any longer, and
  duplicates, keeping one.
- It leaves the ones that match, so the shop is never without webhooks, and creates the new ones before it deletes the
  old: Shipmondo calls a webhook's endpoint when it is created, so one it refuses leaves the old one working.
- When nothing differs, it only fetches the webhooks.
- A webhook on the account whose action or resource the Shipmondo SDK doesn't know, e.g. another integration's, is
  read with that value as null (section 29): another integration's is left alone, and the shop's is replaced.
- Once Shipmondo has answered every call, it records the registration: the endpoint and the time. A registration that
  failed part of the way isn't recorded. When it changed something, it logs what at info level.

**Schedule `setono:sylius-shipmondo:register-webhooks` daily, and run it on deploy**, so an upgrade that changes the
webhooks the plugin handles, or a webhook deleted or deactivated in Shipmondo, is fixed by itself. Pass `--hostname` if
your channels have several hostnames, since the command can't ask which one there (section 18).

**An install that shares another's webhooks key, e.g. a staging copy of production, needs a
`SHIPMONDO_WEBHOOKS_KEY` of its own** if it registers the webhooks too: both have the same shop id, so each
registration replaces the other install's webhooks with ones at its own endpoint.

**The admin's Shipmondo page compares the shop's webhooks when it loads**, at the endpoint of the latest registration,
as it fetches the payment gateways and shipment templates, and shows where and when they were last registered, and
whether they match: the missing ones, and the ones to remove. Before they are registered, it asks for nothing. When it
can't fetch them, the section says so, and the flash says why: once for all three lists when each failed for the same
reason, else once per list. **If you override its template** (`admin/shipmondo/index.html.twig`), read
`registeredWebhooks` (the latest registration, or null) and `webhookComparison` (a `Registrar\WebhookComparison`, or
null before they are registered, or when they couldn't be fetched).

**Run a database migration**: the `setono_sylius_shipmondo__registered_webhooks` table loses its `version` column and
gets a nullable `endpoint` column. Generate it with `bin/console doctrine:migrations:diff` as usual. On MySQL it is:

```sql
ALTER TABLE setono_sylius_shipmondo__registered_webhooks ADD endpoint VARCHAR(255) DEFAULT NULL, DROP version;
```

Its rows keep their `registeredAt`, and have no endpoint until the webhooks are registered again.

Translations, in the `messages` domain under `setono_sylius_shipmondo.ui`: new `missing_webhook`, `unwanted_webhook`,
`unwanted_inactive_webhook` (all take `%name%`, the last two `%endpoint%` too), `webhooks_do_not_match`,
`webhooks_do_not_match_next_step`, `webhooks_match` and `webhooks_not_loaded`; changed
`register_webhooks_information`, and `webhooks_registered_information`, which takes `%endpoint%` too. In the `flashes`
domain: new `webhooks_not_fetched` and `shipmondo_data_and_webhooks_not_fetched` (both take `%reason%`), and changed
`webhooks_not_registered`.

Only if you extend or call the code involved:

| Before | Now |
|---|---|
| `Registrar\WebhookRegistrarInterface::register(): void` | compares and fixes the difference, instead of replacing every webhook |
| | new on it: `compare(string $endpoint): Registrar\WebhookComparison`, which changes nothing; `$endpoint` is the URL Shipmondo sends the webhooks to |
| `WebhookRegistrarInterface::getWebhooks()` and `getVersion()` | removed: `compare()` returns the wanted webhooks Shipmondo is missing |
| `Registrar\WebhookRegistrar::__construct(...)` | takes a `Psr\Log\LoggerInterface` last (`logger`) |
| `Model\RegisteredWebhooksInterface::getVersion()` and `setVersion()` | removed: `getEndpoint(): ?string` and `setEndpoint(?string $endpoint): void` |
| `Repository\RegisteredWebhooksRepositoryInterface::findOneByVersion()` | removed: `findLatest(): ?RegisteredWebhooksInterface`, the latest registration |

**If you implement or decorate `WebhookRegistrarInterface`**, implement `compare()` too, and record the registration's
endpoint and time in `register()`, or the admin's Shipmondo page says the webhooks aren't registered.

New: the `Registrar\WebhookComparison` class.

### Unchanged

Shipping-method / payment-method mapping otherwise behaves as in 1.x. Everything else that changed is described
above: the SDK types, the `sandbox` switch, webhook verification and registration, the webhook reactions, the
cancel-deletes-in-Shipmondo behaviour, how a failed upload is handled, the `cancelled` Shipmondo state with the
link to the sales order, the checked pickup point, how a failed call to Shipmondo is explained, removing a mapping on
the admin Shipmondo page, the one rule for a sales order's amounts, choosing the shipment template, the service ids,
flagging the mappings that no longer exist in Shipmondo, saying when the admin Shipmondo page couldn't fetch a list,
counting the shipping promotions in the shipping line, replacing only this shop's webhooks when registering them,
registering them at the channels' hostname on the command line, registering nothing at an endpoint Shipmondo
can't reach, registering them right away instead of through the command bus, taking the authorized amount, the
payment method and the payment gateway from the payments that paid the order, the time limits on the requests to
Shipmondo, the `unlinked` Shipmondo state, retrying the upload of a failed order, the sales order lookup, recording
and showing why an order's upload didn't go through, the order id prefix, the upload run's stop rules, the PHP 8.2
requirement with the Shipmondo SDK's 2.0.0-rc.3, and registering the webhooks by their difference.
