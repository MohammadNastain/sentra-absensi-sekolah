# syntax=docker/dockerfile:1
# Image PHP untuk PENGEMBANGAN (CLI + Composer). Image produksi dirancang terpisah di Tahap 6.
# Versi dipin; alasan pemilihan di docs/adr/0002-versi-dependency.md.

FROM composer:2.10.3 AS composer

FROM php:8.5.11-cli-alpine3.23

ARG EXT_INSTALLER_VERSION=2.12.0
ARG HOST_UID=1000
ARG HOST_GID=1000

ADD --chmod=0755 \
    https://github.com/mlocati/docker-php-extension-installer/releases/download/${EXT_INSTALLER_VERSION}/install-php-extensions \
    /usr/local/bin/install-php-extensions

# pdo_pgsql/pgsql: PostgreSQL · intl: format tanggal/angka id-ID · zip: XLSX · gd: gambar/QR
# bcmath: aritmetika presisi · pcntl: sinyal queue worker
# Klien Redis (phpredis vs predis) diputuskan di Tahap 1; belum dipasang.
RUN install-php-extensions pdo_pgsql pgsql intl zip gd bcmath pcntl \
    && apk add --no-cache git unzip postgresql18-client

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-sentra-dev.ini

RUN addgroup -g "${HOST_GID}" app \
    && adduser -D -u "${HOST_UID}" -G app app \
    && mkdir -p /var/www/api/vendor \
    && chown -R app:app /var/www
# Named volume yang baru dibuat mewarisi kepemilikan direktori vendor di atas (user app).

USER app
WORKDIR /var/www/api
CMD ["php", "-v"]
