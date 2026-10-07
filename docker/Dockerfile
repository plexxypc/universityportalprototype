# Production image: production PHP dependencies, built assets, then
# nginx + php-fpm + supervisor in one container.

# Symfony 8.1 in composer.lock requires PHP 8.4.1 or newer.
FROM php:8.4-fpm-bookworm AS php_base

ENV DEBIAN_FRONTEND=noninteractive \
    PORT=8080 \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info

RUN printf '#!/bin/sh\nexit 101\n' > /usr/sbin/policy-rc.d \
    && chmod +x /usr/sbin/policy-rc.d \
    && printf 'Acquire::Retries "5";\n' > /etc/apt/apt.conf.d/80-retries \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        nginx \
        supervisor \
        libpng16-16 \
        libjpeg62-turbo \
        libfreetype6 \
        libzip4 \
        libicu72 \
        libonig5 \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        mbstring \
        intl \
        zip \
        gd \
        bcmath \
        opcache \
    && apt-get purge -y --auto-remove \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        libicu-dev \
        libonig-dev \
        $PHPIZE_DEPS \
    && apt-get install -y --no-install-recommends \
        libpng16-16 \
        libjpeg62-turbo \
        libfreetype6 \
        libzip4 \
        libicu72 \
        libonig5 \
    && rm -rf /var/lib/apt/lists/* /usr/sbin/policy-rc.d

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php/zz-docker.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/supervisord.conf /etc/supervisor/supervisord.conf
COPY docker/nginx.conf.template /var/www/html/docker/nginx.conf.template
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN sed -i 's/\r$//' \
        /usr/local/bin/entrypoint.sh \
        /usr/local/etc/php/conf.d/zz-production.ini \
        /usr/local/etc/php-fpm.d/www.conf \
        /usr/local/etc/php-fpm.d/zz-docker.conf \
        /etc/supervisor/supervisord.conf \
        /var/www/html/docker/nginx.conf.template \
    && chmod 755 /usr/local/bin/entrypoint.sh

FROM php_base AS php_dependencies

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

COPY . .

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction --no-scripts \
    && APP_ENV=local APP_DEBUG=true php artisan package:discover --ansi \
    && rm -f /usr/bin/composer

FROM node:22-bookworm-slim AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY --from=php_dependencies /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views

RUN npm run build

FROM php_base AS runtime

WORKDIR /var/www/html

COPY --from=php_dependencies /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

RUN sed -i 's/\r$//' /var/www/html/docker/nginx.conf.template \
    && mkdir -p \
        storage/app/public \
        storage/app/private \
        storage/app/certs \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R ug+rwx storage bootstrap/cache

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl --fail --silent --show-error "http://127.0.0.1:${PORT}/up" > /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
