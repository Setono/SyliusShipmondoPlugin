# Request time limits through framework scoped HTTP clients

Every request to Shipmondo needs a time limit, and the shop's pickup point search, which a customer waits for in checkout, a shorter one than the rest. The plugin prepends two framework scoped HTTP clients, `setono_sylius_shipmondo.http_client` and `setono_sylius_shipmondo.pickup_points_http_client`, and gives the SDK client a PSR-18 client of its own that sends the pickup point search through the PSR-18 version of the one and every other call through the other's, instead of adding plugin options for the limits or an option that names a PSR-18 client service. Shops change a limit in framework config they already know, override one key and keep the rest, reach every other HTTP option (a proxy, TLS settings), see the calls in the Symfony profiler, and the plugin adds no options of its own.

## Considered Options

- **Plugin options** (`setono_sylius_shipmondo.api.timeout` and the like): the plugin builds the HTTP client itself, and needs another option for every HTTP setting a shop wants, such as a proxy.
- **An option naming a PSR-18 client service**: full control, but a shop that only wants another limit has to build a whole client, and the plugin can't give it defaults.

## Consequences

- The plugin requires `symfony/http-client`, and `framework.http_client` must be enabled.
- A shop's `framework.http_client.default_options` apply to the requests to Shipmondo.
- The prepended clients don't retry, but Symfony copies a shop's configured `default_options.retry_failed` (e.g. its `max_retries`, not just `true`) onto the scoped clients named in the same config file, so a shop that configures it and overrides one of the plugin's clients in one file turns that client's retries on, whatever the plugin prepends. Symfony's retry repeats even a POST, which can create a sales order twice. UPGRADE.md tells such a shop to repeat `retry_failed: false`; the plugin doesn't prevent it, since a shop may turn retries on deliberately.
