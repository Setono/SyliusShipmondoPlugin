# Sylius plugin for Shipmondo

[![Latest Version][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]

## Installation

```bash
composer require setono/sylius-shipmondo-plugin symfony/webhook
```

### Add plugin class to your `bundles.php`

Make sure you add it before `SyliusGridBundle`.

```php
<?php
$bundles = [
    // ...
    Setono\SyliusShipmondoPlugin\SetonoSyliusShipmondoPlugin::class => ['all' => true],
    Sylius\Bundle\GridBundle\SyliusGridBundle::class => ['all' => true],
    // ...
];
```

### Import routing

```yaml
# config/routes/setono_sylius_shipmondo.yaml
setono_sylius_shipmondo:
    resource: "@SetonoSyliusShipmondoPlugin/Resources/config/routes.yaml"
```

or if your app doesn't use locales:

```yaml
# config/routes/setono_sylius_shipmondo.yaml
setono_sylius_shipmondo:
    resource: "@SetonoSyliusShipmondoPlugin/Resources/config/routes_no_locale.yaml"
```

### Add environment variables

Add the following variables to your `.env` file:

```dotenv
###> setono/sylius-shipmondo-plugin ###
SHIPMONDO_USERNAME=
SHIPMONDO_KEY=
SHIPMONDO_WEBHOOKS_KEY=
SHIPMONDO_SANDBOX=false
###< setono/sylius-shipmondo-plugin ###
```

`SHIPMONDO_WEBHOOKS_KEY` signs the webhooks Shipmondo sends you and must be at least 32 bytes long.
Set `SHIPMONDO_SANDBOX=true` to use the Shipmondo sandbox instead of the production API.

### Override template

The shipping method form has to be overridden to be able to edit the `pickupPointDelivery` and `carrierCode` properties on the shipping methods.

If you haven't created the file yet, create `templates/bundles/SyliusAdminBundle/ShippingMethod/_form.html.twig`
and add `{{ form_row(form.pickupPointDelivery) }}` and `{{ form_row(form.carrierCode) }}` where you want it.
An example could be to add it next to the `enabled` field:

```twig
...

<div class="three fields">
    {{ form_row(form.enabled) }}
    {{ form_row(form.pickupPointDelivery) }}
    {{ form_row(form.carrierCode) }}
</div>

...
```

which will look like this in the default admin panel:

![Shipping method extra fields](docs/images/shipping-method-extra-fields.png)

### Extend entities

#### `Order` entity

```php
<?php

# src/Entity/Order/Order.php

declare(strict_types=1);

namespace App\Entity\Order;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface as ShipmondoOrderInterface;
use Setono\SyliusShipmondoPlugin\Model\OrderTrait as ShipmondoOrderTrait;
use Sylius\Component\Core\Model\Order as BaseOrder;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 *
 * @ORM\Table(name="sylius_order")
 */
class Order extends BaseOrder implements ShipmondoOrderInterface
{
    use ShipmondoOrderTrait;
}
```

#### `PaymentMethod` entity

```php
<?php

# src/Entity/Payment/PaymentMethod.php

declare(strict_types=1);

namespace App\Entity\Payment;

use Doctrine\ORM\Mapping as ORM;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface as ShipmondoPaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodTrait as ShipmondoPaymentMethodTrait;
use Sylius\Component\Core\Model\PaymentMethod as BasePaymentMethod;

/**
 * @ORM\Entity
 *
 * @ORM\Table(name="sylius_payment_method")
 */
class PaymentMethod extends BasePaymentMethod implements ShipmondoPaymentMethodInterface
{
    use ShipmondoPaymentMethodTrait;
}
```

#### `ShippingMethod` entity

```php
<?php

# src/Entity/Shipping/ShippingMethod.php

declare(strict_types=1);

namespace App\Entity\Shipping;

use Doctrine\ORM\Mapping as ORM;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface as ShipmondoShippingMethodInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodTrait as ShipmondoShippingMethodTrait;
use Sylius\Component\Core\Model\ShippingMethod as BaseShippingMethod;

/**
 * @ORM\Entity
 *
 * @ORM\Table(name="sylius_shipping_method")
 */
class ShippingMethod extends BaseShippingMethod implements ShipmondoShippingMethodInterface
{
    use ShipmondoShippingMethodTrait;
}
```

#### `Shipment` entity

```php
<?php

# src/Entity/Shipping/Shipment.php

declare(strict_types=1);

namespace App\Entity\Shipping;

use Doctrine\ORM\Mapping as ORM;
use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface as ShipmondoShipmentInterface;
use Setono\SyliusShipmondoPlugin\Model\ShipmentTrait as ShipmondoShipmentTrait;
use Sylius\Component\Core\Model\Shipment as BaseShipment;

/**
 * @ORM\Entity
 *
 * @ORM\Table(name="sylius_shipment")
 */
class Shipment extends BaseShipment implements ShipmondoShipmentInterface
{
    use ShipmondoShipmentTrait;
}
```

### Update your database:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

## Cancelled orders and asynchronous deletion

When an order is cancelled in Sylius, the plugin deletes its sales order in Shipmondo through the `DeleteOrder` command
on the `setono_sylius_shipmondo.command_bus` bus. It is handled synchronously by default, and a failing Shipmondo call
never fails the cancellation: the admin is told to delete the sales order in Shipmondo, and the failure is logged.

To delete the sales order in a worker instead, route the command to an asynchronous transport:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            'Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder': async
```

A worker retries a deletion that fails temporarily (a Shipmondo server error or a network problem) or is rate limited,
with the transport's own retry strategy; the plugin configures none. A deletion that trying again won't change (e.g.
Shipmondo doesn't accept the credentials) goes straight to the failure transport, as does one that runs out of
retries, so watch the failure transport:

```shell
php bin/console messenger:failed:show
```

A failed deletion is not retried, only logged, when it is handled inside a database transaction other than the command
bus's own: when `DeleteOrder` is routed to `sync://`, and when a worker wraps the handling of messages in a transaction
of its own.

## Development

```shell
(cd tests/Application && yarn install)
(cd tests/Application && yarn build)
(cd tests/Application && bin/console assets:install)

(cd tests/Application && bin/console doctrine:database:create)
(cd tests/Application && bin/console doctrine:schema:create)

(cd tests/Application && bin/console sylius:fixtures:load -n)

(cd tests/Application && symfony serve -d)

vendor/bin/expose token <your expose token>
vendor/bin/expose default-server free # If you are not paying for Expose
vendor/bin/expose share https://127.0.0.1:8000
```

[ico-version]: https://poser.pugx.org/setono/sylius-shipmondo-plugin/v/stable
[ico-license]: https://poser.pugx.org/setono/sylius-shipmondo-plugin/license
[ico-github-actions]: https://github.com/Setono/SyliusShipmondoPlugin/actions/workflows/build.yaml/badge.svg
[ico-code-coverage]: https://codecov.io/gh/Setono/SyliusShipmondoPlugin/branch/master/graph/badge.svg

[link-packagist]: https://packagist.org/packages/setono/sylius-shipmondo-plugin
[link-github-actions]: https://github.com/Setono/SyliusShipmondoPlugin/actions
[link-code-coverage]: https://codecov.io/gh/Setono/SyliusShipmondoPlugin
