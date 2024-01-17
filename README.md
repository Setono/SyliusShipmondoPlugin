# Sylius plugin for Shipmondo

[![Latest Version][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Build Status][ico-github-actions]][link-github-actions]
[![Code Coverage][ico-code-coverage]][link-code-coverage]

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
