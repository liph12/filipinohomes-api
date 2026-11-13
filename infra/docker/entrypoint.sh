#!/bin/sh
# Entrypoint for every role (web, worker, scheduler, migrate). Laravel's config, route, event and
# view caches are built here at start rather than in the image, because the config cache captures
# the settings and secrets the ECS task starts with.
set -e

php artisan optimize

exec "$@"
