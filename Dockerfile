# syntax=docker/dockerfile:1.7
# ─── Stage 1: PHP dependencies ───────────────────────────────────────────────
# Se instalan primero porque Tailwind v4/Vite necesita resolver CSS desde
# vendor/ (ej: vendor/kore-ui/kore-ui/resources/css/kore-theme.css).
FROM composer:2 AS vendor

WORKDIR /app

# Copiar todo el código para que el classmap optimizado incluya las clases de la app.
# --no-scripts evita ejecutar `php artisan package:discover` aquí (la imagen composer
# no trae todas las extensiones PHP). Se hace en runtime via entrypoint.
COPY . .
RUN composer install \
    --no-dev \
    --no-interaction \
    --prefer-dist \
    --no-scripts \
    --optimize-autoloader \
    --ignore-platform-reqs

# ─── Stage 2: Build frontend assets ──────────────────────────────────────────
FROM node:20-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
# vendor/ está en .dockerignore — copiamos desde el stage anterior para que
# Vite pueda resolver @import de koreUi (y cualquier otro paquete con CSS).
COPY --from=vendor /app/vendor ./vendor

RUN npm run build

# ─── Stage 3: PHP-FPM production image ───────────────────────────────────────
FROM php:8.4-fpm-alpine

# Release marker — se pasa con `--build-arg GIT_SHA=$(git rev-parse --short HEAD)`.
# Sentry lo lee como SENTRY_RELEASE para correlacionar errores con deploys.
ARG GIT_SHA=unknown
ENV SENTRY_RELEASE=$GIT_SHA

# System libraries necesarias para extensiones PHP estándar
RUN apk add --no-cache \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    su-exec

# PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        gd \
        bcmath \
        opcache \
        pcntl \
        mbstring \
        zip \
        exif \
        intl

# Redis PECL extension
RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .phpize-deps

WORKDIR /var/www/html

# Application source code
COPY --chown=www-data:www-data . .

# vendor/ desde Stage 1 (sin dev, optimized)
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor

# Frontend assets desde Stage 2
# A /tmp/assets — en runtime public/build es un volumen compartido con Nginx
# y el entrypoint sincroniza desde /tmp/assets.
COPY --chown=www-data:www-data --from=assets /app/public/build /tmp/assets

# Directorios writable + permisos
RUN mkdir -p \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/cache/data \
        storage/logs \
        public/build \
    && chown -R www-data:www-data storage bootstrap/cache public/build \
    && chmod -R 775 storage bootstrap/cache

# PHP configuration
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www-data

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
