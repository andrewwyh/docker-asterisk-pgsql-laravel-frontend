FROM php:8.5-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev unzip libzip-dev \
    && docker-php-ext-install -j2 pdo_pgsql zip \
    && apt-get clean
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY app/ ./
ENV COMPOSER_ALLOW_SUPERUSER=1

FROM base AS development
RUN composer install --no-interaction --prefer-dist

FROM base AS runtime
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p /var/lib/laravel \
    && chown -R www-data:www-data /var/lib/laravel storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/admin-entrypoint
RUN chmod 755 /usr/local/bin/admin-entrypoint
USER www-data
ENTRYPOINT ["admin-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginxinc/nginx-unprivileged:stable-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY app/public/ /var/www/html/public/
