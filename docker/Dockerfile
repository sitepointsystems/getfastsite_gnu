# ── build stage: composer dependencies ────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --ignore-platform-reqs

# ── runtime ────────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        nginx supervisor curl git openssh-client su-exec \
        nodejs npm ffmpeg zstd \
        chromium nss freetype harfbuzz ttf-freefont \
        icu-libs sqlite-libs libsodium libzip libc6-compat \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev sqlite-dev libsodium-dev libzip-dev \
    && docker-php-ext-install -j$(nproc) pdo_sqlite intl sodium opcache pcntl zip \
    && apk del .build-deps

COPY docker/php.ini /usr/local/etc/php/conf.d/fastsite.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /app
COPY . /app
COPY --from=vendor /build/vendor /app/vendor

# Build flavor. public is the only flavor in this distribution; the trimmer
# script is idempotent on this tree.
ARG FLAVOR=public
RUN sh scripts/deploy-${FLAVOR}.sh

# Node deps for the media (sharp) + component (esbuild/react) pipelines, and
# Playwright for the site-card screenshot. Installed in the runtime image so
# native binaries match this image's node ABI. Playwright uses the system
# chromium (its own musl download doesn't run on Alpine), so skip that download.
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
    CHROME_BIN=/usr/bin/chromium-browser
RUN npm ci --omit=dev --no-audit --no-fund \
    && npm cache clean --force

RUN mkdir -p /data /app/var/log \
    && chown -R www-data:www-data /data /app/var

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
