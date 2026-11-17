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

mkdir -p var/classes/DataObject \
         var/cache \
         var/log \
         var/generic-data-index/snapshots \
         public/bundles

chown -R www-data:www-data var public/bundles /data/var/classes
chmod -R u+rwX,g+rwX var public/bundles /data/var/classes

echo "Installing bundle assets..."

su -s /bin/bash www-data -c 'php bin/console assets:install public --env=prod'

echo "Running database migrations..."
# composer installs the latest 2026.x-dev on every build, so bundles can ship new tables
# (e.g. the OAuth token tables used by the MCP connector).
su -s /bin/bash www-data -c 'php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=prod' \
    || echo "WARNING: migrations failed, continuing"

echo "Clearing Symfony cache..."

su -s /bin/bash www-data -c 'php bin/console cache:clear --env=prod'

echo "Starting PHP-FPM..."
php-fpm -D -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=0

CONSOLE='php -d opcache.enable_cli=0 bin/console'

run_forever() {
    # $1 = consumer arguments; restarted after every exit (time limit, class save, crash)
    (
        while true; do
            su -s /bin/bash www-data -c "$CONSOLE messenger:consume $1 --env=prod --time-limit=300 --memory-limit=256M --no-interaction -v" || true
            sleep 2
        done
    ) &
}

echo "Starting Messenger workers..."
# Worker 1: index queue + the GDI scheduler that moves index queue entries to the transport every 10s.
# Without scheduler_generic_data_index new objects never reach OpenSearch -> Studio tree/detail 404.
run_forever "pimcore_generic_data_index_queue scheduler_generic_data_index"
# Worker 2: a second index worker (class mapping copies block a worker) + everything else
run_forever "pimcore_generic_execution_engine pimcore_core pimcore_generic_data_index_queue pimcore_maintenance pimcore_scheduled_tasks pimcore_image_optimize pimcore_asset_update pimcore_cdn_purge"

echo "Starting maintenance loop..."
(
    while true; do
        sleep 300
        su -s /bin/bash www-data -c "$CONSOLE pimcore:maintenance --env=prod" || true
    done
) &

echo "Starting Nginx..."
exec nginx -g 'daemon off;'
