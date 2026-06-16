#!/usr/bin/env sh
set -eu

PORT="${PORT:-10000}"

mkdir -p \
  /var/www/html/storage/app/public \
  /var/www/html/storage/framework/cache \
  /var/www/html/storage/framework/sessions \
  /var/www/html/storage/framework/views \
  /var/www/html/storage/logs \
  /var/www/html/bootstrap/cache

if [ ! -L /var/www/html/public/storage ]; then
  php artisan storage:link >/dev/null 2>&1 || true
fi

sed -ri "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

apache2-foreground
