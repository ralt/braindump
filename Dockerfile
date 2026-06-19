# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1 — build whisper.cpp so on-device transcription works with no API key.
# ---------------------------------------------------------------------------
FROM debian:bookworm-slim AS whisper-builder

ARG WHISPER_CPP_VERSION=v1.7.4
ARG WHISPER_MODEL=base.en

RUN apt-get update && apt-get install -y --no-install-recommends \
    git build-essential cmake ca-certificates wget \
    && rm -rf /var/lib/apt/lists/*

RUN git clone --depth 1 --branch "${WHISPER_CPP_VERSION}" https://github.com/ggerganov/whisper.cpp.git /opt/whisper.cpp \
    && cmake -S /opt/whisper.cpp -B /opt/whisper.cpp/build -DCMAKE_BUILD_TYPE=Release -DWHISPER_BUILD_EXAMPLES=ON \
    && cmake --build /opt/whisper.cpp/build -j "$(nproc)" --config Release --target whisper-cli

# Bake a model so the app transcribes immediately. Lives outside /app so a /app/var
# volume mount can't hide it.
RUN mkdir -p /models \
    && wget -q -O "/models/ggml-${WHISPER_MODEL}.bin" \
       "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-${WHISPER_MODEL}.bin"

# ---------------------------------------------------------------------------
# Stage 2 — the application image (FrankenPHP, mirrors the Symfony Cloud runtime).
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS app

# System deps: ffmpeg for audio transcoding, libs whisper.cpp links against at runtime.
RUN apt-get update && apt-get install -y --no-install-recommends \
    ffmpeg libgomp1 unzip git \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions (same set as the Symfony Cloud config, plus pdo_sqlite for the zero-config default
# and zip so Composer can extract dist packages).
RUN install-php-extensions pdo_pgsql pdo_sqlite sodium intl mbstring ctype iconv xsl opcache zip

# Composer isn't bundled in the FrankenPHP image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY --from=whisper-builder /opt/whisper.cpp/build/bin/whisper-cli /usr/local/bin/whisper-cli
COPY --from=whisper-builder /models /models

WORKDIR /app

# Install PHP dependencies first (better layer caching).
COPY composer.json composer.lock symfony.lock ./
ENV APP_ENV=prod
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

# Application code.
COPY . .
# Compile the frontend: assets:install copies bundle assets (EasyAdmin, etc.) into public/,
# and asset-map:compile builds the AssetMapper/importmap output into public/assets/ — without
# it the Stimulus controllers 404 in prod and JS-driven UI (e.g. the mic picker) never loads.
# APP_SECRET is only set at runtime, so give the build a throwaway value to boot the kernel.
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && APP_SECRET=build php bin/console assets:install public --no-interaction \
    && APP_SECRET=build php bin/console asset-map:compile \
    && mkdir -p var/cache var/log storage/audio var/whisper \
    && rm -rf var/cache/prod \
    && chmod -R 777 var storage

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
