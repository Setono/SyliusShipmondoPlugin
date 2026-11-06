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
  run stops early (section 6)
- move the cancelled orders that 1.x left pending or failed to the new `cancelled` Shipmondo state (section 7)
- if you override the order grid's Shipmondo state label templates, rename `dispatching.html.twig` and
  `dispatched.html.twig` (section 7)
- run a database migration, which only adds a comment to the shipment's pickup point column (section 8)
- note that a shipping method with pickup point delivery now needs a pickup point in checkout, and don't let a
  channel skip the shipping step when its only shipping method has pickup point delivery (section 8)

The renamed SDK classes and the other class changes only matter if you extend the plugin.

> The plugin still targets **PHP 8.1+**, **Symfony 6.4** and **Sylius ~1.14** — those requirements
> are unchanged.

### 1. Update the dependency

```bash
composer require setono/sylius-shipmondo-plugin:^2.0
```

This pulls in `setono/shipmondo-php-sdk:^2.0` (2.0.0-beta.2 or later).

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
upgrading. The SDK signs and verifies deliveries with HS256, which requires a key of at least 32 bytes,
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
| `Registrar\WebhookRegistrar::__construct($client, $urlGenerator, string $webhooksKey, string $namePrefix)` | `__construct($client, $urlGenerator, Webhook\Handler\OrderWebhookHandlers $handlers, string $webhooksKey, string $namePrefix)`; `getWebhooks()` yields one webhook per action on the orders resource that a handler subscribes to |
| `Registrar\Webhook::$action` / `$resource` are strings | they are the SDK's `WebhookAction` / `WebhookResourceName` enums |

New:

- `Webhook\Handler\OrderWebhookHandlerInterface` (see above);
- the plugin's three webhook handlers `Webhook\Handler\SalesOrderShippedHandler`, `SalesOrderPaymentHandler` and
  `SalesOrderDeletedHandler` (services `setono_sylius_shipmondo.webhook.handler.sales_order_shipped`,
  `.sales_order_payment` and `.sales_order_deleted`);
- `Webhook\Handler\OrderWebhookHandlers` (service `setono_sylius_shipmondo.webhook.order_webhook_handlers`), the
  handlers of each action, which `DependencyInjection\Compiler\RegisterOrderWebhookHandlersPass` fills when the
  container is built;
- `Webhook\OrderResolverInterface` (service `setono_sylius_shipmondo.webhook.order_resolver`), which links a webhook
  payload to its Sylius order;
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

A failing Shipmondo call never blocks the cancellation. A sales order that is already gone in Shipmondo
(404) counts as deleted. Any other failure is logged, the order stays `uploaded_to_shipmondo` and keeps its
`shipmondoId`, and, when the command is handled synchronously, the admin sees an error telling them to delete the order in Shipmondo
manually. **When `DeleteOrder` is routed to an async transport, a failure is only logged and is not
retried** (see [#16](https://github.com/Setono/SyliusShipmondoPlugin/issues/16)), so watch the logs.

### 6. A failed upload settles the order instead of crashing the upload run

In 1.x, any exception during an upload (a Shipmondo error, a network problem, a data mapper that throws) escaped
`setono:sylius-shipmondo:upload-orders`: the upload run aborted, and the order stayed in `uploading_to_shipmondo`
for good. In 2.x, `Uploader\OrderUploader` settles every failure on the order:

| Failure | Order ends in | Synchronous upload run |
|---|---|---|
| Shipmondo rejects the upload: a 4xx other than 401, 403 and 429 (e.g. 422) | `failed` | continues |
| The order can't be turned into a sales order (a data mapper throws), or another unexpected error, such as a non-error status (e.g. an unfollowed redirect) or an answer the SDK can't read | `failed` | continues |
| Shipmondo doesn't accept the API credentials: 401 or 403 | `pending` | stops |
| Shipmondo rate limits the upload: 429 | `pending` | stops |
| A temporary failure: 5xx, a network error or a timeout | `pending` | continues |
| The order changed while it was uploading, before its `UploadOrder` command was handled | `pending` | continues |

- A synchronous upload run also stops after three rejected uploads with no successful upload between them, because
  Shipmondo answers 422 for shop-wide problems too, such as a mapped payment gateway that was deleted in Shipmondo.
- **When the upload run stops early, the command prints why and exits with a failure code**, and the remaining orders
  stay `pending` for the next run. Otherwise the command stays silent and succeeds, also when single uploads failed:
  each failure is logged with the order number and Shipmondo's status code and error message, and a `failed` order
  stays `failed` until an admin steps in.
- Going back to pending uses the new `retry` transition (`OrderWorkflow::TRANSITION_RETRY`,
  `uploading_to_shipmondo` → `pending`) of the `setono_sylius_shipmondo__order` workflow. `reset` is unchanged.
- Retrying after a temporary failure can create a duplicate sales order, when Shipmondo created the sales order but
  its answer was lost (see [#29](https://github.com/Setono/SyliusShipmondoPlugin/issues/29)).
- **If you route `UploadOrder` to an async transport:** its handler no longer throws for a failed upload, so Messenger
  no longer retries it; the next upload run is the retry. The upload run can't see how async uploads go, so it never
  stops early and **the command always succeeds: watch the logs instead**. An async worker can't stop the `UploadOrder`
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
| `Uploader\OrderUploaderInterface::upload(): void` | `upload(): ?Uploader\UploadOutcome`: the outcome that stopped the run early, or `null` |
| | new `OrderUploaderInterface::uploadOrder(OrderInterface $order, ?int $version = null): UploadOutcome`, which uploads one order in `uploading_to_shipmondo` and settles its state |
| `Uploader\OrderUploader::__construct($provider, $commandBus, $orderWorkflow, $managerRegistry)` | `__construct($provider, $commandBus, $orderWorkflow, Setono\Shipmondo\Client\ClientInterface $shipmondoClient, SalesOrderDataMapperInterface $salesOrderDataMapper, Canceller\OrderCancellerInterface $orderCanceller, Psr\Log\LoggerInterface $logger, $managerRegistry)` |
| `Message\CommandHandler\UploadOrderHandler::__construct($orderRepository, $shipmondoClient, $salesOrderDataMapper, $orderWorkflow)`; `__invoke()` returns `void` | `__construct($orderRepository, OrderUploaderInterface $orderUploader)`; `__invoke()` returns the `UploadOutcome` |
| `Workflow\OrderWorkflow::getWorkflow()` returns a `Workflow`, on which `fail` is never enabled | returns a `StateMachine` with the transitions the container registers (see [#38](https://github.com/Setono/SyliusShipmondoPlugin/issues/38)) |

New: the `Uploader\UploadOutcome` enum, which says how an upload went.

### 7. A cancelled order's Shipmondo state becomes cancelled, and only an uploaded order is linked to a sales order

In 1.x, cancelling an order left its Shipmondo state as it was, e.g. `pending` (see
[#5](https://github.com/Setono/SyliusShipmondoPlugin/issues/5)). The `setono_sylius_shipmondo__order` workflow now
has a `cancelled` state (`OrderInterface::SHIPMONDO_STATE_CANCELLED`) and a `cancel` transition
(`OrderWorkflow::TRANSITION_CANCEL`) into it from `pending` and `failed`, the states in which Shipmondo has no sales
order for the order. A guard on the workflow (`Workflow\OrderCancellationGuard`, service
`setono_sylius_shipmondo.workflow.order_cancellation_guard`) lets `cancel` through only for an order that is cancelled
in Sylius. When an order is cancelled in Sylius, it depends on its Shipmondo state:

| Shipmondo state | What happens |
|---|---|
| `pending` or `failed` | the order becomes `cancelled` right away |
| `uploaded_to_shipmondo` | its sales order is deleted (section 5): the order is `reset` to `pending` and then becomes `cancelled`. If the deletion fails, the order stays `uploaded_to_shipmondo`; delete the sales order in Shipmondo, and the `orders/delete` webhook makes the order `cancelled` the same way |
| `uploading_to_shipmondo` | it is left to its upload, which makes it `cancelled` once the upload is settled. If Shipmondo created the sales order meanwhile, that sales order is deleted first |

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
| | `Message\CommandHandler\DeleteOrderHandler::__construct($orderRepository, $shipmondoClient, Symfony\Component\Workflow\WorkflowInterface $orderWorkflow, Canceller\OrderCancellerInterface $orderCanceller, $logger)` |

New: `Canceller\OrderCancellerInterface` and `Canceller\OrderCanceller` (service `setono_sylius_shipmondo.canceller.order`),
which make the Shipmondo state of an order that is cancelled in Sylius cancelled too, and the `Canceller\Cancellation`
enum, which says what happened; `Workflow\OrderCancellationGuard`; the `OrderInterface::SHIPMONDO_STATE_CANCELLED`,
`OrderWorkflow::TRANSITION_CANCEL` and `OrderWorkflow::CONTEXT_SALES_ORDER_ID` constants.

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

### Unchanged

Shipping-method / payment-method mapping behaves exactly as in 1.x. Everything else that changed is described
above: the SDK types, the `sandbox` switch, webhook verification and registration, the webhook reactions, the
cancel-deletes-in-Shipmondo behaviour, how a failed upload is handled, the `cancelled` Shipmondo state with the
link to the sales order, and the checked pickup point.
