#!/bin/sh
set -e

ROLE="${1:-php-fpm}"

case "$ROLE" in
    queue)
        echo "[entrypoint] Warming caches for queue worker..."
        php artisan config:cache
        php artisan view:cache

        echo "[entrypoint] Starting queue worker..."
        exec php artisan queue:work \
            --sleep=3 \
            --tries=3 \
            --max-time=3600 \
            --queue=default
        ;;

    scheduler)
        echo "[entrypoint] Warming caches for scheduler..."
        php artisan config:cache
        php artisan view:cache

        echo "[entrypoint] Starting scheduler..."
        exec php artisan schedule:work
        ;;

    php-fpm|*)
        echo "[entrypoint] Syncing frontend assets to shared volume..."
        cp -rf /tmp/assets/. /var/www/html/public/build/ 2>/dev/null || true
        chown -R www-data:www-data /var/www/html/public/build || true

        echo "[entrypoint] Clearing stale build-time caches..."
        rm -f /var/www/html/bootstrap/cache/packages.php
        rm -f /var/www/html/bootstrap/cache/services.php
        rm -f /var/www/html/bootstrap/cache/config.php

        echo "[entrypoint] Discovering packages..."
        su-exec www-data php artisan package:discover --ansi

        echo "[entrypoint] Running database migrations..."
        su-exec www-data php artisan migrate --force

        echo "[entrypoint] Warming application caches..."
        su-exec www-data php artisan config:cache
        su-exec www-data php artisan route:cache
        su-exec www-data php artisan view:cache

        echo "[entrypoint] Starting PHP-FPM..."
        exec php-fpm
        ;;
esac
