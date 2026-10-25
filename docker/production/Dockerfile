# syntax=docker/dockerfile:1

# --- Stage 1: composer deps -------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --no-interaction \
        --prefer-dist \
        --ignore-platform-req=ext-gd

COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# --- Stage 2: runtime --------------------------------------------------------
FROM php:8.5-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx \
        supervisor \
        libpq-dev \
    && rm -rf /var/lib/apt/lists/*

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions \
        pdo_pgsql \
        pgsql \
        intl \
        bcmath \
        zip \
        gd \
        exif \
        pcntl \
        opcache \
        redis

# Same non-root user (and group — php-fpm-pool.conf's `group = app` needs it
# to actually exist) the dev image uses.
RUN groupadd --force -g 1000 app \
    && useradd -o -u 1000 -g app -m -s /bin/bash app

WORKDIR /var/www/html

COPY --from=vendor --chown=app:app /app /var/www/html

# Same-origin deploy: the SPA's own build (gym-trainer-spa/dist, copied into
# this build context as docker/production/spa-dist right before `docker
# build` / `gcloud builds submit` — see the deploy notes) served by nginx
# alongside the API, one domain, so Sanctum's session cookie works in every
# browser (no cross-site cookie blocking).
COPY docker/production/spa-dist /var/www/spa

COPY docker/production/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/production/nginx.conf /etc/nginx/sites-enabled/default
COPY docker/production/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/production/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# nginx needs root to bind low ports and write its own runtime files; php-fpm
# workers still run as "app" per the pool config above. Matches how the
# nginx:alpine + php-fpm images behave split across two containers in dev.
USER root

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
