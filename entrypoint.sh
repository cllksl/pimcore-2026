#!/bin/bash

set -e

cd /var/www/html

echo "Preparing Pimcore directories..."

mkdir -p var/cache var/log public/var

chown -R www-data:www-data var public/var

echo "Starting PHP-FPM..."

php-fpm -D

echo "Starting Nginx..."

exec nginx -g 'daemon off;'
