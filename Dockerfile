# syntax=docker/dockerfile:1

############################################################
# Stage 1: vendor — composer dependencies
# Same PHP as the runtime stage so composer's platform
# checks match what the app will actually run on.
############################################################
FROM dunglas/frankenphp:1-php8.5 AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN install-php-extensions pdo_mysql intl zip gd bcmath pcntl opcache

WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: post-autoload-dump runs `artisan package:discover`,
# which needs the full app source; discovery runs in the runtime stage.
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-scripts

############################################################
# Stage 2: assets — Vite build
# app.css imports vendor/livewire/flux/dist/flux.css and
# @sources vendor globs, so vendor/ must be present.
############################################################
FROM node:22-bookworm-slim AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY --from=vendor /app/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

############################################################
# Stage 3: runtime
############################################################
FROM dunglas/frankenphp:1-php8.5 AS runtime

RUN install-php-extensions pdo_mysql intl zip gd bcmath pcntl opcache

# poppler-utils: pdftotext for bank-statement PDF import
# curl: container healthchecks against /up
RUN apt-get update && apt-get install -y --no-install-recommends \
        poppler-utils \
        curl \
    && rm -rf /var/lib/apt/lists/*

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini $PHP_INI_DIR/conf.d/99-lineledger.ini

WORKDIR /app
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Replaces the post-autoload-dump composer script skipped in the vendor stage.
RUN php artisan package:discover --ansi

# Non-root; setcap lets frankenphp bind 80/443 for direct-HTTPS setups.
RUN useradd --uid 1000 --home-dir /app --shell /bin/bash lineledger \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R lineledger:lineledger /app /data /config
USER lineledger

# Plain HTTP on 8080 by default (for use behind a reverse proxy).
# Set SERVER_NAME to a domain and publish 80/443 for automatic HTTPS.
ENV SERVER_NAME=:8080 \
    CONTAINER_ROLE=app \
    APP_ENV=production \
    APP_DEBUG=false

EXPOSE 8080 80 443 443/udp

HEALTHCHECK --interval=15s --timeout=5s --start-period=90s --retries=5 \
    CMD curl -fsS http://localhost:8080/up || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
