FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        gosu \
        git \
        unzip \
        $PHPIZE_DEPS \
        libzip-dev \
        libicu-dev \
        default-mysql-client \
    && docker-php-ext-install pdo_mysql bcmath intl zip opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN rm -f bootstrap/cache/*.php \
    && composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader --no-scripts \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache \
    && chmod -R a+rX app bootstrap config database docker lang public resources routes vendor artisan composer.json composer.lock \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /var/www/html/docker-entrypoint.sh

COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/php.ini /usr/local/etc/php/conf.d/fleet.ini

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r '$cmd = str_replace(chr(0), " ", (string) @file_get_contents("/proc/1/cmdline")); if (str_contains($cmd, "artisan queue:work") || str_contains($cmd, "artisan schedule:work")) { exit(0); } exit(@file_get_contents("http://127.0.0.1:8080/health") === false ? 1 : 0);'

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
