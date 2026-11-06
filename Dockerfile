# KonektaDOST: Laravel 12 + React (Inertia) served by Apache with PHP 8.4.
#
#   docker compose up -d --build        build and start locally (see README, "Docker")
#   docker build -t <registry>/<project>/konektadost:<tag> .    what CI runs (.gitlab-ci.yml)
#
# The image contains no .env or secrets: settings are passed at run time.
#
# Stages: php-base (runtime + extensions) -> vendor (Composer, production packages)
#         -> assets (Node, Vite build) -> app (final image).

ARG PHP_VERSION=8.4
ARG NODE_VERSION=22

# ---------------------------------------------------------------------------------------------
# 1. PHP runtime with Apache and the extensions this project needs
#    (mbstring, OPcache, curl, xml, fileinfo, iconv and zlib are already in the base image)
# ---------------------------------------------------------------------------------------------
FROM php:${PHP_VERSION}-apache AS php-base

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype-dev libjpeg62-turbo-dev libpng-dev libicu-dev libzip-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip intl bcmath exif pcntl \
    && rm -rf /var/lib/apt/lists/*

# Serve Laravel's public/ folder; public/.htaccess needs mod_rewrite.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite headers
COPY docker/apache.conf /etc/apache2/conf-available/konektadost.conf
RUN a2enconf konektadost

# Production php.ini plus project settings (time limit for GeoAgent, memory, OPcache).
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-konektadost.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# ---------------------------------------------------------------------------------------------
# 2. Composer packages (production only; the lock file pins every version)
# ---------------------------------------------------------------------------------------------
FROM php-base AS vendor

RUN apt-get update && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

# ---------------------------------------------------------------------------------------------
# 3. Frontend assets. Debian-based Node: package.json pins the glibc (linux-x64-gnu) build tools.
# ---------------------------------------------------------------------------------------------
FROM node:${NODE_VERSION}-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js tsconfig.json ./
COPY resources ./resources
RUN npm run build

# ---------------------------------------------------------------------------------------------
# 4. Application image
# ---------------------------------------------------------------------------------------------
FROM php-base AS app

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Optimized autoloader; also runs package:discover (no database or .env needed).
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm -f public/hot \
    && mkdir -p storage/app/diagnostics storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache /var/www/.config \
    && chown -R www-data:www-data storage bootstrap/cache /var/www/.config

COPY docker/entrypoint.sh /usr/local/bin/konektadost-entrypoint
# Strip Windows line endings in case the file was checked out with CRLF.
RUN sed -i 's/\r$//' /usr/local/bin/konektadost-entrypoint && chmod +x /usr/local/bin/konektadost-entrypoint

EXPOSE 80
# Laravel's built-in health route (application boots; does not query the database).
HEALTHCHECK --interval=30s --timeout=5s --start-period=180s --retries=3 \
    CMD curl -fsS http://localhost/up >/dev/null || exit 1

ENTRYPOINT ["konektadost-entrypoint"]
CMD ["apache2-foreground"]
