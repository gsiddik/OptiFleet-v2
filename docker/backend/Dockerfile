# Build context: repository root
#
# PHP 8.4, not 8.3: backend/composer.lock pins symfony/{clock,css-selector,
# event-dispatcher,string,translation,yaml} v8.1.x, which require php >=8.4.1.
# On 8.3 every one of those fails the platform check and `composer install`
# aborts. Keep this image and the lockfile's PHP floor in step.
FROM php:8.4-fpm-alpine

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

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY backend/. .
RUN composer dump-autoload --optimize --no-dev

COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
