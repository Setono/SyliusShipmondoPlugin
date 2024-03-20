# Sylius plugin for Shipmondo

[![Latest Version][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]

## Installation

```bash
composer require setono/sylius-shipmondo-plugin
```

### Import routing:

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

### Add plugin class to your `bundles.php`:

Make sure you add it before `SyliusGridBundle`, otherwise you'll get
`You have requested a non-existent parameter "setono_sylius_shipmondo.model.remote_event.class".` exception.

```php
<?php
$bundles = [
    // ...
    Setono\SyliusShipmondoPlugin\SetonoSyliusShipmondoPlugin::class => ['all' => true],
    Sylius\Bundle\GridBundle\SyliusGridBundle::class => ['all' => true],
    // ...
];
```

### Extend entities

**Extend `Order`**

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
 * @ORM\Table(name="sylius_order")
 */
class Order extends BaseOrder implements ShipmondoOrderInterface
{
    use ShipmondoOrderTrait;
}
```

- Add configuration:

```yaml
# config/packages/_sylius.yaml
sylius_order:
    resources:
        order:
            classes:
                model: App\Entity\Order\Order
```

### Update your database:

```bash
$ bin/console doctrine:migrations:diff
$ bin/console doctrine:migrations:migrate
```

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
[ico-github-actions]: https://github.com/Setono/SyliusShipmondoPlugin/workflows/build/badge.svg
[ico-code-coverage]: https://codecov.io/gh/Setono/SyliusShipmondoPlugin/branch/master/graph/badge.svg

[link-packagist]: https://packagist.org/packages/setono/sylius-shipmondo-plugin
[link-github-actions]: https://github.com/Setono/SyliusShipmondoPlugin/actions
[link-code-coverage]: https://codecov.io/gh/Setono/SyliusShipmondoPlugin
