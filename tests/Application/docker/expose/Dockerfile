# Expose tunnel client.
#
# Gives the local test-app server (run with `symfony serve`) a public HTTPS URL so external
# services — e.g. Shipmondo, which calls a webhook endpoint at creation time and expects an
# HTTP 200 — can reach it while developing locally.
#
# See README.md in this directory for usage.
FROM php:8.1-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/composer

# Pin to the same major as the beyondcode/expose dev dependency used by the plugin
RUN composer global require beyondcode/expose:^2.6 \
 && ln -s /composer/vendor/bin/expose /usr/local/bin/expose

# expose stores its auth token / default server in ~/.expose (HOME=/root). Mount a host volume
# there (see README) so the token set with `expose token` persists between `docker run` calls.
ENTRYPOINT ["expose"]
