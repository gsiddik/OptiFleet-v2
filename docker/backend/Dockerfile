# Build context: repository root. The root .dockerignore keeps .env files, vendor/, caches, logs, uploads, tests and docs out of the image.
#
# PHP 8.4, not 8.3: backend/composer.lock pins symfony/{clock,css-selector,
# event-dispatcher,string,translation,yaml} v8.1.x, which require php >=8.4.1.
# On 8.3 every one of those fails the platform check and `composer install`
# aborts. Keep this image and the lockfile's PHP floor in step.
ARG PHP_IMAGE=php:8.4-fpm-alpine
ARG COMPOSER_IMAGE=composer:2

FROM ${COMPOSER_IMAGE} AS composer-bin

FROM ${PHP_IMAGE}

# Building the PHP extensions below is the memory peak of this image, and
# ext/opcache/jit/zend_jit.c alone needs ~1 GiB in a single cc1. Without -j1,
# docker-php-ext-install/pecl fan out to nproc parallel compiles and the OOM
# killer takes out cc1 on small dev VMs (e.g. a 2 GiB podman machine).
# Do NOT "fix" that by passing --disable-opcache-jit to docker-php-ext-configure:
# that builds an opcache.so which then fails to relocate at runtime
# (Error relocating opcache.so: zend_jit_status: symbol not found).
ENV MAKEFLAGS="-j1"

# ext-mongodb is pinned to 1.x deliberately: composer.lock resolves
# mongodb/mongodb 1.21.4, which requires ext-mongodb ^1.21.0. An unpinned
# `pecl install mongodb` now installs 2.x and fails that platform check.
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    openssl-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install pdo pdo_pgsql pgsql bcmath intl zip opcache \
    && pecl install redis mongodb-1.21.9 \
    && docker-php-ext-enable redis mongodb \
    && pecl clear-cache \
    && apk del $PHPIZE_DEPS

COPY --from=composer-bin /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# COMPOSER_INSTALL_FLAGS lets a mirror-only network use --prefer-source; the default is the normal path.
ARG COMPOSER_INSTALL_FLAGS="--prefer-dist"
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction ${COMPOSER_INSTALL_FLAGS}

COPY backend/. .
RUN composer dump-autoload --optimize --no-dev --no-interaction

COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh

# Every PHP process of this image — php-fpm, the entrypoint's artisan steps, queue-worker,
# scheduler and `docker compose exec backend php artisan …` — runs as www-data, the owner of
# storage/. Mixing users was the root cause of "Unable to create a directory at
# storage/app/private/<feature>/<tenant>": a root-run artisan command (e.g. seeding demo
# documents) created 0700 root-owned folders that the www-data web process could not enter.
# su-exec lets the entrypoint repair ownership and drop privileges when started as root.
RUN apk add --no-cache su-exec \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# The entrypoint starts as root only to repair ownership of mounted volumes, then continues as www-data.
EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
