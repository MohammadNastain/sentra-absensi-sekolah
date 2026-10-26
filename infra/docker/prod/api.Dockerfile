# syntax=docker/dockerfile:1
# Image API produksi: FrankenPHP (Caddy + PHP 8.5) + Laravel tanpa dependency dev.
# Konteks build = root repo:  docker build -f infra/docker/prod/api.Dockerfile -t sentra-api .
# Satu image dipakai empat peran: edge+API (bawaan), `queue`, `scheduler`, dan `migrate` (lihat infra/prod/compose.yaml).
# Setelah build pertama yang sukses, sematkan FROM ke digest (sha256) agar build berikutnya identik.

FROM composer:2.10.3 AS composer

FROM dunglas/frankenphp:1-php8.5-alpine AS base

# Ekstensi sama dengan image dev (infra/docker/php/Dockerfile) + opcache. Klien Redis: predis (murni PHP).
RUN install-php-extensions pdo_pgsql pgsql intl zip gd bcmath pcntl opcache \
    && apk add --no-cache libcap tini

COPY infra/docker/prod/php.ini /usr/local/etc/php/conf.d/zz-sentra-prod.ini

FROM base AS vendor
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY apps/api/ ./
RUN composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction \
    && rm -rf /root/.composer /tmp/*

FROM base AS runtime
COPY --from=vendor /app /app
COPY infra/docker/prod/Caddyfile /etc/frankenphp/Caddyfile
COPY infra/docker/prod/api-entrypoint.sh /usr/local/bin/api-entrypoint
RUN chmod +x /usr/local/bin/api-entrypoint

# Non-root: ikat port 80/443 lewat capability, dan beri www-data hak tulis ke storage + data Caddy.
RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /app/storage/app/private /app/storage/framework/cache /app/storage/framework/sessions /app/storage/framework/views /app/storage/logs /app/bootstrap/cache /data/caddy /config/caddy \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /data /config

USER www-data
WORKDIR /app
ENV APP_ENV=production
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/api-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
