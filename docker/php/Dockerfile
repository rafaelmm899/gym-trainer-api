# syntax=docker/dockerfile:1

FROM php:8.5-fpm-bookworm

# --- Base tooling ----------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions ------------------------------------------------------
# mlocati/docker-php-extension-installer pulls in the right system libs itself.
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

# --- Composer ----------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Non-root user matched to the host uid/gid (clean dev bind mounts) ---
ARG UID=1000
ARG GID=1000
RUN groupadd --force -g ${GID} app \
    && useradd -o -u ${UID} -g app -m -s /bin/bash app \
    && mkdir -p /var/www/html \
    && chown app:app /var/www/html

# --- PHP / FPM configuration ------------------------------------------
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html
USER app

EXPOSE 9000
CMD ["php-fpm"]
