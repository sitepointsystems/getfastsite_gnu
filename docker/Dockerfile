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

# Build flavor: private (default) keeps the endpointr translator, public keeps
# the OpenRouter one. The scripts delete the file the flavor must not ship;
# an unknown FLAVOR fails the build loudly (no such script).
ARG FLAVOR=private
RUN sh scripts/deploy-${FLAVOR}.sh

# Node deps for the media (sharp) + component (esbuild/react) pipelines, and
# Playwright for the site-card screenshot. Installed in the runtime image so
# native binaries match this image's node ABI. Playwright uses the system
# chromium (its own musl download doesn't run on Alpine), so skip that download.
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
    CHROME_BIN=/usr/bin/chromium-browser
RUN npm ci --omit=dev --no-audit --no-fund \
    && npm cache clean --force

# Stamp the image so a running container can say WHAT it is without a shell on
# the host (read back by Fastsite\Ops\BuildInfoService, exposed on
# GET /api/v1/build, MCP get_build_info and `fastsite doctor`). Coolify passes
# SOURCE_COMMIT when it has one; the timestamp alone already answers the
# question that keeps coming up — is this image newer than the push I am
# waiting for, or did the old one survive a failed build? A 200 from /healthz
# cannot tell those apart, which is exactly how a shipped security fix got
# reported as missing.
ARG SOURCE_COMMIT=""
RUN mkdir -p /data /app/var/log \
    && printf '{"sha":"%s","built_at":"%s","flavor":"%s"}\n' \
        "${SOURCE_COMMIT}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${FLAVOR}" > /app/var/build.json \
    && chown -R www-data:www-data /data /app/var

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
