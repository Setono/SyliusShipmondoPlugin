# Upgrade guide

## From 1.x to 2.x

2.x migrates the plugin from **`setono/shipmondo-php-sdk` v1 to v2**. The SDK reorganised its
request/response classes (singularised namespaces, enums instead of class constants) and the
`Client` gained a `sandbox` flag. For most installations the only required change is the new
`SHIPMONDO_SANDBOX` environment variable — the renamed SDK classes only matter if you extend the
plugin's data-mapping layer.

> The plugin still targets **PHP 8.1+**, **Symfony 6.4** and **Sylius ~1.14** — those requirements
> are unchanged.

### 1. Update the dependency

```bash
composer require setono/sylius-shipmondo-plugin:^2.0
```

This pulls in `setono/shipmondo-php-sdk:^2.0`.

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
  (back to `pending`, clearing its `shipmondoId`), so the next `upload-orders` run re-uploads it. It is
  deliberately *not* treated as a cancellation — cancelling an order is a Sylius-side decision.
- `orders / payment_captured` completes the Sylius payment(s) → the order's payment state becomes `paid`
  (orders are uploaded while either `paid` or `authorized`, so an authorized payment can be captured
  later in Shipmondo), and `orders / payment_voided` cancels the order's payment state.

Reactions run through a tagged **`RemoteEventHandlerInterface`** framework (tag
`setono_sylius_shipmondo.remote_event_handler`), so you can add your own handlers — the same way you
add data mappers. A handler receives the Shipmondo resource object directly (the webhook's `data`
envelope is unwrapped by the parser), and reads the event's resource/action via
`RemoteEvent::getResource()` / `getAction()`, which now return the SDK enums
`Setono\Shipmondo\Enum\WebhookResourceName` / `WebhookAction` instead of strings.

Webhook verification and parsing are delegated to the SDK's `Setono\Shipmondo\Webhook\WebhookParser`
(the plugin requires `setono/shipmondo-php-sdk:^2.0` and `firebase/php-jwt:^7.0`). Shipmondo identifies
each delivery with `SMD-*` request headers, so the registered endpoint no longer carries
`resource`/`action` query parameters — **re-run `setono:sylius-shipmondo:register-webhooks`** after
upgrading. The SDK signs and verifies deliveries with HS256, which requires a key of at least 32 bytes,
so **`SHIPMONDO_WEBHOOKS_KEY` must now be at least 32 bytes long** (a shorter key is rejected both when
registering and when verifying).

Because of this, the **write-only `RemoteEvent` Sylius resource/entity was removed**: the
`setono_sylius_shipmondo__remote_event` table is no longer used and **can be dropped** (generate a
migration in your app). If you referenced `Setono\SyliusShipmondoPlugin\Model\RemoteEvent(Interface)`
or `RemoteEventFactory`, those are gone. (The unrelated `Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent`
value object used by the webhook parser/handlers remains.)

### Unchanged

Shipping-method / payment-method mapping, webhook registration and order upload behave exactly as in
1.x — only the underlying SDK types, the new `sandbox` switch, and the new webhook reactions changed.
