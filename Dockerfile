FROM dunglas/frankenphp:1-php8.4

ENV APP_ENV=prod \
    APP_DEBUG=0

RUN install-php-extensions @composer opcache intl \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /app

COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-interaction

COPY app/ .

RUN composer dump-autoload --optimize --classmap-authoritative \
 && php bin/console importmap:install \
 && php bin/console tailwind:build --minify \
 && php bin/console asset-map:compile \
 && php bin/console cache:warmup \
 && mkdir -p var/lobbies var/sessions

COPY docker/Caddyfile /etc/docker/Caddyfile
