#!/bin/sh
# Container entrypoint: cache config/routes with the runtime secrets, then hand over to supervisord.
set -e
cd /var/www/html
php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
