# syntax=docker/dockerfile:1
############################################
# base: PHP 8.3 + extensions + composer
############################################
FROM php:8.3-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libzip-dev libicu-dev postgresql-client \
    && docker-php-ext-install pdo_pgsql pcntl bcmath zip intl opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MEMORY_LIMIT=-1
WORKDIR /var/www/html

############################################
# app: Laravel skeleton + packages + our overlay (backend/)
# Used by: app (php-fpm), reverb, queue, scheduler, migrate, tests
############################################
FROM base AS app
RUN composer create-project laravel/laravel:^12.0 . --no-interaction --prefer-dist \
 && composer require --no-interaction \
        laravel/sanctum laravel/reverb predis/predis firebase/php-jwt \
        league/flysystem-aws-s3-v3:^3.0 dedoc/scramble \
 # We ship our own UUID-based schema, so drop the skeleton's bigint-id migrations, web routes and .env.
 && rm -f database/migrations/*.php routes/web.php .env database/database.sqlite tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php
COPY backend/ /var/www/html/
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
 && composer dump-autoload --optimize --no-interaction \
 && chown -R www-data:www-data storage bootstrap/cache
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

############################################
# media: same code + FFmpeg, resource-limited in compose
############################################
FROM app AS media
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*
