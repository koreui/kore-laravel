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

        # queue:restart escribe una marca de tiempo en la caché (Redis en
        # producción). Los workers del servicio `queue` la miran entre job y
        # job: terminan el que tengan entre manos y salen limpiamente, en vez de
        # seguir con el esquema y la config viejos en memoria hasta que les toque
        # `--max-time`. Cierra la ventana entre «migrate --force ya corrió» y
        # «el worker todavía no se ha enterado».
        # OJO: esto NO cambia el código del worker. El contenedor `queue` se
        # relevanta con la imagen con la que fue creado, así que un despliegue de
        # código nuevo sigue necesitando `up -d --no-deps app queue scheduler`.
        # Va DESPUÉS de config:cache para que use la caché ya escrita, y con
        # `|| true` porque en el primer arranque Redis puede no estar listo y
        # una marca que no se escribe no debe tumbar el contenedor.
        echo "[entrypoint] Signalling queue workers to restart (new code + migrations)..."
        su-exec www-data php artisan queue:restart || true

        echo "[entrypoint] Starting PHP-FPM..."
        exec php-fpm
        ;;
esac
