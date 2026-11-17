#!/bin/bash
set -e

cd /var/www/html

echo "Preparing persistent storage..."

if ! mountpoint -q /data; then
    echo "ERROR: Persistent volume is not mounted."
    exit 1
fi

for DIR in var/classes var/config public/var; do
    TARGET="/data/$DIR"

    if [ ! -d "$TARGET" ]; then
        mkdir -p "$TARGET"

        if [ -d "$DIR" ]; then
            cp -a "$DIR/." "$TARGET/"
        fi
    fi

    mkdir -p "$(dirname "$DIR")"
    rm -rf "$DIR"
    ln -s "$TARGET" "$DIR"

    chown -R www-data:www-data "$TARGET"
    chmod -R u+rwX,g+rwX "$TARGET"
done

echo "Preparing Pimcore directories..."

mkdir -p var/cache \
         var/log \
         var/generic-data-index/snapshots \
         public/bundles

chown -R www-data:www-data var public/bundles
chmod -R u+rwX,g+rwX var public/bundles

echo "Installing bundle assets..."

su -s /bin/bash www-data -c 'php bin/console assets:install public --env=prod'

echo "Clearing Symfony cache..."

su -s /bin/bash www-data -c 'php bin/console cache:clear --env=prod'

echo "Starting PHP-FPM..."
php-fpm -D

echo "Starting Nginx..."
exec nginx -g 'daemon off;'
