# Production image for VerdantCart B2B Sync (used on Fly.io).
# One container runs everything for the demo: nginx + php-fpm (web), Horizon (queue workers),
# the scheduler (sync:retry-due every minute) and a local Redis, supervised by supervisord.
FROM php:8.3-fpm-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends nginx supervisor redis-server libpq-dev libicu-dev libzip-dev unzip git ca-certificates \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl intl zip opcache bcmath \
 && apt-get purge -y --auto-remove libpq-dev libicu-dev libzip-dev \
 && apt-get install -y --no-install-recommends libpq5 libicu72 libzip4 \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first so this layer is cached between code changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY deploy/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY deploy/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY deploy/nginx.conf /etc/nginx/sites-enabled/default
COPY deploy/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY deploy/start.sh /usr/local/bin/start
RUN chmod +x /usr/local/bin/start

EXPOSE 8080
CMD ["/usr/local/bin/start"]
