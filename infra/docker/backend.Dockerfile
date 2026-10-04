# Laravel backend: php-fpm for HTTP, the same image runs queue, scheduler and Reverb.
FROM php:8.4-fpm-alpine AS base

RUN apk add --no-cache icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev postgresql-dev linux-headers $PHPIZE_DEPS \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" intl zip gd pdo_pgsql pcntl bcmath opcache sockets \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS linux-headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/backend

FROM base AS app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm"]
