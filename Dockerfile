# syntax=docker/dockerfile:1
#
# Filipino Homes API. One image runs every role; the ECS task definition picks the command:
#   web        (default) FrankenPHP serving public/ on port 8080
#   worker     php artisan queue:work …
#   scheduler  filipinohomes-scheduler (php artisan schedule:work, waiting for running commands on stop)
#   artisan    one-off commands (php artisan migrate:status, migrate --force, …)
#
# Build from the repo root (linux/amd64 for x86 ECS instances; --provenance=false keeps it a
# single image manifest in ECR):
#   docker build --platform linux/amd64 --provenance=false -t filipinohomes/api:local .

# Base images are pinned by digest as well as tag, so a commit always builds from the same bases.
# Bump the tag and the digest together.
#
# Debian 13 (trixie), the current Debian release: Debian 12's ffmpeg, ghostscript and libtiff carried
# 3 critical and dozens of high CVEs in ECR's scan.
ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.13.4-php8.3-trixie@sha256:1202855937a80746c89cb82598df72c204f67162ed7af9ace1b5cb7791496d04

# Frontend assets. Only the welcome page uses @vite, but without public/build/manifest.json
# "/" fails with a 500.
FROM node:22.23.3-bookworm-slim@sha256:3ed98443660ff48413b5af19ecefce3b9d50845a901a43420d47cc63a9fddbe9 AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# PHP runtime for the build and final stages. Extensions beyond the base image: pdo_mysql, gd
# (Intervention Image's GD driver), exif (photo orientation), zip (webklex/php-imap), pcntl
# (queue:work timeouts and graceful stop), redis. Binaries: ffmpeg (YouTube listing videos) and
# ghostscript (PDF optimisation).
FROM ${FRANKENPHP_IMAGE} AS runtime
RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg ghostscript \
 && rm -rf /var/lib/apt/lists/*
RUN install-php-extensions pdo_mysql gd exif zip pcntl redis

# Production Composer dependencies, then the optimised autoloader. `composer install` also checks
# that every PHP extension the locked packages need is present in the runtime above.
FROM runtime AS vendor
COPY --from=composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public storage/framework/cache/data \
      storage/framework/sessions storage/framework/views storage/framework/testing storage/logs \
 && composer dump-autoload --optimize --no-dev --no-interaction

# Final image: code owned by root and read-only to the app; only storage/ and bootstrap/cache are
# writable. Runs as an unprivileged user on port 8080, so FrankenPHP doesn't need the capability
# to bind low ports.
FROM runtime
WORKDIR /app
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY infra/docker/php.ini "$PHP_INI_DIR/conf.d/zz-filipinohomes.ini"
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY infra/docker/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 infra/docker/entrypoint.sh /usr/local/bin/filipinohomes-entrypoint
COPY --chmod=755 infra/docker/scheduler.sh /usr/local/bin/filipinohomes-scheduler
# --chmod also sets the mode of the directory COPY creates, which needs the x bit to be entered.
COPY --chmod=755 infra/docker/env-from-secret.php /usr/local/lib/filipinohomes/env-from-secret.php
RUN useradd --uid 1000 --no-create-home --shell /usr/sbin/nologin app \
 && setcap -r /usr/local/bin/frankenphp \
 && mkdir -p /config/caddy /data/caddy \
 && chown -R app:app /app/storage /app/bootstrap/cache /config/caddy /data/caddy
USER app
EXPOSE 8080
# The base image's check probes the admin endpoint, which the Caddyfile turns off. Health checks
# belong to each role instead: the load balancer checks /up on web; worker and scheduler serve no HTTP.
HEALTHCHECK NONE
ENTRYPOINT ["filipinohomes-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
